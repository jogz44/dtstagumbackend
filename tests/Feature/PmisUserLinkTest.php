<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\PmisEmployee;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PmisUserLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_users_survive_and_have_null_pmis_employee_id(): void
    {
        $office = Office::create(['name' => 'HR Office', 'code' => 'HRO']);

        $user = User::create([
            'username' => 'testuser',
            'password' => bcrypt('password'),
            'first_name' => 'Test',
            'last_name' => 'User',
            'office_id' => $office->id,
            'is_active' => true,
        ]);

        $this->assertNull($user->pmis_employee_id);
        $this->assertNull($user->pmisEmployee);
    }

    public function test_valid_pmis_employee_can_be_linked_to_user(): void
    {
        $office = Office::create(['name' => 'HR Office', 'code' => 'HRO']);

        $employee = PmisEmployee::create([
            'pmis_no' => '099999',
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'birth_date' => '1990-08-13',
            'office' => 'HR Office',
            'designation' => 'Officer',
            'employment_status' => 'Permanent',
        ]);

        $user = User::create([
            'username' => 'jdelacruz',
            'password' => bcrypt('password'),
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'office_id' => $office->id,
            'pmis_employee_id' => $employee->id,
            'is_active' => true,
        ]);

        $this->assertEquals($employee->id, $user->pmis_employee_id);
        $this->assertEquals($employee->id, $user->pmisEmployee->id);
        $this->assertEquals($user->id, $employee->user->id);
    }

    public function test_duplicate_pmis_employee_link_is_blocked_at_db_level(): void
    {
        $office = Office::create(['name' => 'HR Office', 'code' => 'HRO']);

        $employee = PmisEmployee::create([
            'pmis_no' => '088888',
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birth_date' => '1995-05-15',
            'office' => 'HR Office',
            'designation' => 'Staff',
            'employment_status' => 'Casual',
        ]);

        User::create([
            'username' => 'msantos1',
            'password' => bcrypt('password'),
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'office_id' => $office->id,
            'pmis_employee_id' => $employee->id,
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);

        User::create([
            'username' => 'msantos2',
            'password' => bcrypt('password'),
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'office_id' => $office->id,
            'pmis_employee_id' => $employee->id,
            'is_active' => true,
        ]);
    }

    public function test_deleting_user_does_not_delete_pmis_employee(): void
    {
        $office = Office::create(['name' => 'HR Office', 'code' => 'HRO']);

        $employee = PmisEmployee::create([
            'pmis_no' => '077777',
            'first_name' => 'Pedro',
            'last_name' => 'Penduko',
            'birth_date' => '1988-12-25',
            'office' => 'HR Office',
            'designation' => 'Inspector',
            'employment_status' => 'Permanent',
        ]);

        $user = User::create([
            'username' => 'ppenduko',
            'password' => bcrypt('password'),
            'first_name' => 'Pedro',
            'last_name' => 'Penduko',
            'office_id' => $office->id,
            'pmis_employee_id' => $employee->id,
            'is_active' => true,
        ]);

        $user->forceDelete();

        $this->assertDatabaseHas('pmis_employees', [
            'id' => $employee->id,
        ]);
    }

    public function test_deleting_pmis_employee_nulls_user_pmis_employee_id(): void
    {
        $office = Office::create(['name' => 'HR Office', 'code' => 'HRO']);

        $employee = PmisEmployee::create([
            'pmis_no' => '066666',
            'first_name' => 'Jose',
            'last_name' => 'Rizal',
            'birth_date' => '1980-06-19',
            'office' => 'HR Office',
            'designation' => 'Specialist',
            'employment_status' => 'Permanent',
        ]);

        $user = User::create([
            'username' => 'jrizal',
            'password' => bcrypt('password'),
            'first_name' => 'Jose',
            'last_name' => 'Rizal',
            'office_id' => $office->id,
            'pmis_employee_id' => $employee->id,
            'is_active' => true,
        ]);

        $employee->delete();

        $user->refresh();
        $this->assertNull($user->pmis_employee_id);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
        ]);
    }
}
