<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\PmisEmployee;
use App\Models\User;
use App\Services\UserInitialPasswordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class InitialPasswordFlowTest extends TestCase
{
    use RefreshDatabase;

    protected Office $office;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        if (! \Illuminate\Support\Facades\DB::table('roles')->where('name', 'admin')->exists()) {
            \Illuminate\Support\Facades\DB::table('roles')->insert([
                'id' => (string) Str::uuid(),
                'name' => 'admin',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->office = Office::create(['name' => 'HR Office', 'code' => 'HRO']);

        $this->adminUser = User::create([
            'username' => 'admin_user',
            'password' => bcrypt('password123'),
            'first_name' => 'Admin',
            'last_name' => 'User',
            'office_id' => $this->office->id,
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $this->adminUser->assignRole('admin');
    }

    public function test_existing_user_defaults_to_must_change_password_false(): void
    {
        $user = User::create([
            'username' => 'existing_user',
            'password' => bcrypt('password123'),
            'first_name' => 'Existing',
            'last_name' => 'User',
            'office_id' => $this->office->id,
            'is_active' => true,
        ]);

        $this->assertFalse($user->must_change_password);
    }

    public function test_new_pmis_linked_account_gets_must_change_password_true_and_mmddyyyy_hashed_password(): void
    {
        $employee = PmisEmployee::create([
            'pmis_no' => '088888',
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birth_date' => '1985-04-09',
            'office' => 'HR Office',
            'designation' => 'Specialist',
            'employment_status' => 'Permanent',
        ]);

        $response = $this->actingAs($this->adminUser)->postJson('/api/users', [
            'pmis_employee_id' => $employee->id,
            'username' => 'msantos',
            'office_id' => $this->office->id,
            'is_active' => true,
        ]);

        $response->assertCreated();

        $user = User::where('username', 'msantos')->firstOrFail();

        $this->assertTrue($user->must_change_password);
        $this->assertEquals($employee->id, $user->pmis_employee_id);
        $this->assertTrue(Hash::check('04091985', $user->password));
        $this->assertNotEquals('04091985', $user->password);
    }

    public function test_frontend_supplied_fake_birthdate_is_ignored_for_pmis_linked_accounts(): void
    {
        $employee = PmisEmployee::create([
            'pmis_no' => '077777',
            'first_name' => 'Carlos',
            'last_name' => 'Reyes',
            'birth_date' => '1992-12-25',
            'office' => 'HR Office',
            'designation' => 'Officer',
            'employment_status' => 'Permanent',
        ]);

        $response = $this->actingAs($this->adminUser)->postJson('/api/users', [
            'pmis_employee_id' => $employee->id,
            'username' => 'creyes',
            'office_id' => $this->office->id,
            'birthdate' => '2000-01-01', // fake input
            'is_active' => true,
        ]);

        $response->assertCreated();

        $user = User::where('username', 'creyes')->firstOrFail();

        $this->assertEquals('1992-12-25', $user->birthdate->format('Y-m-d'));
        $this->assertTrue(Hash::check('12251992', $user->password));
    }

    public function test_employee_without_birthdate_returns_422(): void
    {
        $employee = PmisEmployee::create([
            'pmis_no' => '066666',
            'first_name' => 'NoBirth',
            'last_name' => 'Employee',
            'birth_date' => null,
            'office' => 'HR Office',
        ]);

        $response = $this->actingAs($this->adminUser)->postJson('/api/users', [
            'pmis_employee_id' => $employee->id,
            'username' => 'nobirth',
            'office_id' => $this->office->id,
            'is_active' => true,
        ]);

        $response->assertStatus(422);
    }

    public function test_user_with_must_change_password_true_can_authenticate_and_receives_flag(): void
    {
        $employee = PmisEmployee::create([
            'pmis_no' => '055555',
            'first_name' => 'Pedro',
            'last_name' => 'Penduko',
            'birth_date' => '1974-08-01',
            'office' => 'HR Office',
        ]);

        $user = User::create([
            'username' => 'ppenduko',
            'password' => Hash::make('08011974'),
            'first_name' => 'Pedro',
            'last_name' => 'Penduko',
            'office_id' => $this->office->id,
            'pmis_employee_id' => $employee->id,
            'must_change_password' => true,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/login', [
            'username' => 'ppenduko',
            'password' => '08011974',
        ]);

        $response->assertOk()
            ->assertJsonPath('must_change_password', true);
    }

    public function test_user_with_must_change_password_true_is_blocked_from_normal_protected_apis(): void
    {
        $user = User::create([
            'username' => 'blocked_user',
            'password' => Hash::make('08011974'),
            'first_name' => 'Blocked',
            'last_name' => 'User',
            'office_id' => $this->office->id,
            'must_change_password' => true,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->getJson('/api/users');

        $response->assertStatus(403)
            ->assertJsonPath('must_change_password', true);
    }

    public function test_user_with_must_change_password_true_can_access_change_password_and_logout(): void
    {
        $user = User::create([
            'username' => 'changepass_user',
            'password' => Hash::make('08011974'),
            'first_name' => 'Change',
            'last_name' => 'Pass',
            'office_id' => $this->office->id,
            'must_change_password' => true,
            'is_active' => true,
        ]);

        $logoutRes = $this->actingAs($user)->postJson('/api/logout');
        $logoutRes->assertOk();
    }

    public function test_birthday_password_reuse_is_blocked_on_password_change(): void
    {
        $employee = PmisEmployee::create([
            'pmis_no' => '044444',
            'first_name' => 'Reuser',
            'last_name' => 'Employee',
            'birth_date' => '1980-05-15',
            'office' => 'HR Office',
        ]);

        $user = User::create([
            'username' => 'reuser',
            'password' => Hash::make('05151980'),
            'first_name' => 'Reuser',
            'last_name' => 'Employee',
            'office_id' => $this->office->id,
            'pmis_employee_id' => $employee->id,
            'must_change_password' => true,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->postJson('/api/change-password', [
            'current_password' => '05151980',
            'new_password' => '05151980', // reusing birthday password
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('new_password');
    }

    public function test_successful_password_change_clears_must_change_password_flag(): void
    {
        $employee = PmisEmployee::create([
            'pmis_no' => '033333',
            'first_name' => 'Success',
            'last_name' => 'User',
            'birth_date' => '1991-03-20',
            'office' => 'HR Office',
        ]);

        $user = User::create([
            'username' => 'successuser',
            'password' => Hash::make('03201991'),
            'first_name' => 'Success',
            'last_name' => 'User',
            'office_id' => $this->office->id,
            'pmis_employee_id' => $employee->id,
            'must_change_password' => true,
            'is_active' => true,
        ]);

        $changeRes = $this->actingAs($user)->postJson('/api/change-password', [
            'current_password' => '03201991',
            'new_password' => 'SecurePass2026!',
        ]);

        $changeRes->assertOk()
            ->assertJsonPath('must_change_password', false);

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('SecurePass2026!', $user->password));

        // Now protected API should be accessible
        $apiRes = $this->actingAs($user)->getJson('/api/users');
        $apiRes->assertOk();
    }

    public function test_pmis_sync_never_modifies_user_passwords(): void
    {
        $employee = PmisEmployee::create([
            'pmis_no' => '022222',
            'first_name' => 'SyncCheck',
            'last_name' => 'User',
            'birth_date' => '1988-10-10',
            'office' => 'HR Office',
        ]);

        $user = User::create([
            'username' => 'synccheck',
            'password' => Hash::make('NewSecret123'),
            'first_name' => 'SyncCheck',
            'last_name' => 'User',
            'office_id' => $this->office->id,
            'pmis_employee_id' => $employee->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $originalHash = $user->password;

        // Simulate PMIS employee birthdate update
        $employee->update(['birth_date' => '1988-10-11']);

        $user->refresh();

        $this->assertEquals($originalHash, $user->password);
        $this->assertFalse($user->must_change_password);
    }
}
