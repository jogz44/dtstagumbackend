<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\PmisEmployee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PmisEmployeeDirectoryApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $regularUser;
    protected Office $office;

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

        if (! \Illuminate\Support\Facades\DB::table('roles')->where('name', 'employee')->exists()) {
            \Illuminate\Support\Facades\DB::table('roles')->insert([
                'id' => (string) Str::uuid(),
                'name' => 'employee',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->office = Office::create(['name' => 'HR Office', 'code' => 'HRO']);

        $this->adminUser = User::create([
            'username' => 'admin_user',
            'password' => bcrypt('password'),
            'first_name' => 'Admin',
            'last_name' => 'User',
            'office_id' => $this->office->id,
            'is_active' => true,
        ]);
        $this->adminUser->assignRole('admin');

        $this->regularUser = User::create([
            'username' => 'regular_user',
            'password' => bcrypt('password'),
            'first_name' => 'Regular',
            'last_name' => 'User',
            'office_id' => $this->office->id,
            'is_active' => true,
        ]);
        $this->regularUser->assignRole('employee');
    }

    public function test_unauthenticated_request_receives_401(): void
    {
        $response = $this->getJson('/api/pmis-employees');
        $response->assertStatus(401);
    }

    public function test_unauthorized_user_receives_403(): void
    {
        Sanctum::actingAs($this->regularUser);
        $response = $this->getJson('/api/pmis-employees');
        $response->assertStatus(403);
    }

    public function test_authorized_user_can_list_employees(): void
    {
        PmisEmployee::create([
            'pmis_no' => '000111',
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'birth_date' => '1990-08-13',
            'office' => 'HR Office',
            'designation' => 'Officer',
            'employment_status' => 'Permanent',
        ]);

        Sanctum::actingAs($this->adminUser);
        $response = $this->getJson('/api/pmis-employees');

        $response
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.pmis_no', '000111')
            ->assertJsonPath('data.0.full_name', 'Juan Dela Cruz')
            ->assertJsonPath('data.0.birth_date', '1990-08-13')
            ->assertJsonPath('data.0.has_portal_account', false);
    }

    public function test_search_filters_employees_by_pmis_no_name_office_and_designation(): void
    {
        PmisEmployee::create([
            'pmis_no' => '001001',
            'first_name' => 'Alice',
            'last_name' => 'Zubiri',
            'birth_date' => '1985-02-10',
            'office' => 'Mayor Office',
            'designation' => 'Administrator',
            'employment_status' => 'Permanent',
        ]);

        PmisEmployee::create([
            'pmis_no' => '001002',
            'first_name' => 'Bob',
            'last_name' => 'Marley',
            'birth_date' => '1980-04-12',
            'office' => 'Health Office',
            'designation' => 'Doctor',
            'employment_status' => 'Casual',
        ]);

        Sanctum::actingAs($this->adminUser);

        $res1 = $this->getJson('/api/pmis-employees?search=001001');
        $res1->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.first_name', 'Alice');

        $res2 = $this->getJson('/api/pmis-employees?search=Marley');
        $res2->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.first_name', 'Bob');

        $res3 = $this->getJson('/api/pmis-employees?search=Health');
        $res3->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.first_name', 'Bob');
    }

    public function test_office_and_employment_status_filters(): void
    {
        PmisEmployee::create([
            'pmis_no' => '002001',
            'first_name' => 'Charlie',
            'last_name' => 'Brown',
            'birth_date' => '1992-11-20',
            'office' => 'Budget Office',
            'employment_status' => 'Permanent',
        ]);

        PmisEmployee::create([
            'pmis_no' => '002002',
            'first_name' => 'David',
            'last_name' => 'Miller',
            'birth_date' => '1994-07-04',
            'office' => 'Budget Office',
            'employment_status' => 'Casual',
        ]);

        Sanctum::actingAs($this->adminUser);

        $res = $this->getJson('/api/pmis-employees?office=Budget%20Office&employment_status=Casual');
        $res->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.first_name', 'David');
    }

    public function test_available_only_excludes_linked_employees(): void
    {
        $emp1 = PmisEmployee::create([
            'pmis_no' => '003001',
            'first_name' => 'Linked',
            'last_name' => 'Employee',
            'birth_date' => '1991-01-01',
            'office' => 'HR Office',
        ]);

        PmisEmployee::create([
            'pmis_no' => '003002',
            'first_name' => 'Unlinked',
            'last_name' => 'Employee',
            'birth_date' => '1993-03-03',
            'office' => 'HR Office',
        ]);

        User::create([
            'username' => 'linked_user',
            'password' => bcrypt('password'),
            'first_name' => 'Linked',
            'last_name' => 'Employee',
            'office_id' => $this->office->id,
            'pmis_employee_id' => $emp1->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->adminUser);

        $allRes = $this->getJson('/api/pmis-employees');
        $allRes->assertOk()->assertJsonPath('pagination.total', 2);

        $availRes = $this->getJson('/api/pmis-employees?available_only=true');
        $availRes->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.first_name', 'Unlinked');
    }

    public function test_pagination_defaults_and_clamps_page_size(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            PmisEmployee::create([
                'pmis_no' => sprintf('004%03d', $i),
                'first_name' => "Emp{$i}",
                'last_name' => 'Test',
                'birth_date' => '1990-01-01',
            ]);
        }

        Sanctum::actingAs($this->adminUser);

        $resDefault = $this->getJson('/api/pmis-employees');
        $resDefault->assertOk()->assertJsonPath('pagination.per_page', 25)->assertJsonPath('pagination.last_page', 2);

        $resOver = $this->getJson('/api/pmis-employees?per_page=999');
        $resOver->assertOk()->assertJsonPath('pagination.per_page', 100);
    }

    public function test_invalid_query_parameters_return_422(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/api/pmis-employees?per_page=invalid');
        $response->assertStatus(422);
    }
}
