<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Office;
use App\Models\PmisEmployee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PmisUserCreationApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected Office $hrOffice;

    protected Division $recordsDivision;

    protected function setUp(): void
    {
        parent::setUp();

        if (! DB::table('roles')->where('name', 'admin')->exists()) {
            DB::table('roles')->insert([
                'id' => (string) Str::uuid(),
                'name' => 'admin',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! DB::table('roles')->where('name', 'Receiver')->exists()) {
            DB::table('roles')->insert([
                'id' => (string) Str::uuid(),
                'name' => 'Receiver',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->hrOffice = Office::create([
            'name' => 'City Human Resource Management Office',
            'short_name' => 'CHRMO',
            'code' => 'CHRMO-001',
            'is_active' => true,
        ]);

        $this->recordsDivision = Division::create([
            'office_id' => $this->hrOffice->id,
            'name' => 'Personnel Records Section',
            'short_name' => 'PRS',
            'code' => 'CHRMO-PRS-001',
            'is_active' => true,
        ]);

        $this->adminUser = User::create([
            'username' => 'admin_tester',
            'password' => bcrypt('password123'),
            'first_name' => 'System',
            'last_name' => 'Admin',
            'office_id' => $this->hrOffice->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $this->adminUser->assignRole('admin');
    }

    public function test_post_user_creation_with_only_pmis_employee_id_and_role_succeeds(): void
    {
        $employee = PmisEmployee::create([
            'pmis_no' => '018690',
            'first_name' => 'Jonas',
            'middle_name' => 'Tacda',
            'last_name' => 'Calisagan',
            'birth_date' => '1979-07-27',
            'designation' => 'Administrative Officer V',
            'office' => '  city human resource management office  ', // lowercase + extra spaces for normalization test
            'division' => 'PERSONNEL RECORDS SECTION', // uppercase normalization test
            'employment_status' => 'Permanent',
        ]);

        $response = $this->actingAs($this->adminUser)->postJson('/api/users', [
            'pmis_employee_id' => $employee->id,
            'role' => 'Receiver',
        ]);

        $response->assertCreated()
            ->assertJsonPath('username', 'jcalisagan')
            ->assertJsonPath('first_name', 'Jonas')
            ->assertJsonPath('last_name', 'Calisagan')
            ->assertJsonPath('pmis_employee_id', $employee->id)
            ->assertJsonPath('office.id', $this->hrOffice->id)
            ->assertJsonPath('division.id', $this->recordsDivision->id);

        $user = User::where('pmis_employee_id', $employee->id)->firstOrFail();
        $this->assertEquals('jcalisagan', $user->username);
        $this->assertEquals($this->hrOffice->id, $user->office_id);
        $this->assertEquals($this->recordsDivision->id, $user->division_id);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check('07271979', $user->password));
        $this->assertTrue($user->hasRole('Receiver'));
    }

    public function test_username_collision_increments_suffix(): void
    {
        // Existing user with jcalisagan username
        User::create([
            'username' => 'jcalisagan',
            'password' => bcrypt('password123'),
            'first_name' => 'Existing',
            'last_name' => 'Jonas',
            'office_id' => $this->hrOffice->id,
            'is_active' => true,
        ]);

        $employee = PmisEmployee::create([
            'pmis_no' => '018691',
            'first_name' => 'Jane',
            'last_name' => 'Calisagan',
            'birth_date' => '1985-11-12',
            'office' => 'City Human Resource Management Office',
            'employment_status' => 'Permanent',
        ]);

        $response = $this->actingAs($this->adminUser)->postJson('/api/users', [
            'pmis_employee_id' => $employee->id,
            'role' => 'Receiver',
        ]);

        $response->assertCreated()
            ->assertJsonPath('username', 'jcalisagan2');
    }

    public function test_unmatched_office_and_division_resets_to_null_without_guessing(): void
    {
        $employee = PmisEmployee::create([
            'pmis_no' => '099999',
            'first_name' => 'Unmatched',
            'last_name' => 'Employee',
            'birth_date' => '1990-01-01',
            'office' => 'Non Existent Remote Office Name Department 99',
            'division' => 'Unknown Division Subunit',
            'employment_status' => 'Casual',
        ]);

        $response = $this->actingAs($this->adminUser)->postJson('/api/users', [
            'pmis_employee_id' => $employee->id,
            'role' => 'Receiver',
        ]);

        $response->assertCreated()
            ->assertJsonPath('office_id', null)
            ->assertJsonPath('division_id', null);

        $user = User::where('pmis_employee_id', $employee->id)->firstOrFail();
        $this->assertNull($user->office_id);
        $this->assertNull($user->division_id);
    }

    public function test_request_without_pmis_employee_id_is_rejected(): void
    {
        $response = $this->actingAs($this->adminUser)->postJson('/api/users', [
            'first_name' => 'Manual',
            'last_name' => 'User',
            'username' => 'manualuser',
            'role' => 'Receiver',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('pmis_employee_id')
            ->assertJsonPath('errors.pmis_employee_id.0', 'Every portal user account must be linked to a PMIS employee record.');
    }

    public function test_duplicate_pmis_employee_link_is_rejected(): void
    {
        $employee = PmisEmployee::create([
            'pmis_no' => '077777',
            'first_name' => 'Already',
            'last_name' => 'Linked',
            'birth_date' => '1988-05-20',
            'office' => 'City Human Resource Management Office',
        ]);

        User::create([
            'username' => 'alinked',
            'password' => bcrypt('password123'),
            'first_name' => 'Already',
            'last_name' => 'Linked',
            'office_id' => $this->hrOffice->id,
            'pmis_employee_id' => $employee->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->adminUser)->postJson('/api/users', [
            'pmis_employee_id' => $employee->id,
            'role' => 'Receiver',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('pmis_employee_id');
    }

    public function test_employee_without_birthdate_is_rejected(): void
    {
        $employee = PmisEmployee::create([
            'pmis_no' => '055555',
            'first_name' => 'NoBirth',
            'last_name' => 'Date',
            'birth_date' => null,
            'office' => 'City Human Resource Management Office',
        ]);

        $response = $this->actingAs($this->adminUser)->postJson('/api/users', [
            'pmis_employee_id' => $employee->id,
            'role' => 'Receiver',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'This employee does not have a verified birth date in PMIS and cannot be issued the standard temporary password.');
    }
}
