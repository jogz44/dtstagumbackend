<?php

namespace Tests\Feature;

use App\Models\AccountHistoryEvent;
use App\Models\ActivityEvent;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserUpdateApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $targetUser;
    protected Office $office;

    protected function setUp(): void
    {
        parent::setUp();

        if (! DB::table('roles')->where('name', 'super_admin')->exists()) {
            DB::table('roles')->insert([
                'id' => (string) Str::uuid(),
                'name' => 'super_admin',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! DB::table('roles')->where('name', 'employee')->exists()) {
            DB::table('roles')->insert([
                'id' => (string) Str::uuid(),
                'name' => 'employee',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->office = Office::create(['name' => 'HR Office', 'code' => 'HRO']);

        $this->superAdmin = User::create([
            'username' => 'superadmin',
            'password' => bcrypt('password'),
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'office_id' => $this->office->id,
            'birthdate' => '1990-01-01',
            'is_active' => true,
        ]);
        $this->superAdmin->assignRole('super_admin');

        $this->targetUser = User::create([
            'username' => 'john.doe',
            'password' => bcrypt('password'),
            'first_name' => 'John',
            'last_name' => 'Doe',
            'office_id' => $this->office->id,
            'birthdate' => '1995-05-15',
            'position' => 'Staff',
            'is_active' => true,
        ]);
        $this->targetUser->assignRole('employee');

        Sanctum::actingAs($this->superAdmin);
    }

    public function test_user_update_accepts_boolean_is_active(): void
    {
        $response = $this->putJson("/api/users/{$this->targetUser->id}", [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'position' => 'Senior Staff',
            'is_active' => false,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $this->targetUser->id,
            'position' => 'Senior Staff',
            'is_active' => false,
        ]);
    }

    public function test_user_update_normalizes_active_status_label(): void
    {
        $response1 = $this->putJson("/api/users/{$this->targetUser->id}", [
            'is_active' => 'Inactive',
        ]);

        $response1->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $this->targetUser->id,
            'is_active' => false,
        ]);

        $response2 = $this->putJson("/api/users/{$this->targetUser->id}", [
            'is_active' => 'Active',
        ]);

        $response2->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $this->targetUser->id,
            'is_active' => true,
        ]);
    }

    public function test_user_update_rejects_invalid_is_active_string(): void
    {
        $response = $this->putJson("/api/users/{$this->targetUser->id}", [
            'is_active' => 'maybe',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['is_active']);
    }

    public function test_super_admin_protected_fields_cannot_be_changed(): void
    {
        $response = $this->putJson("/api/users/{$this->superAdmin->id}", [
            'first_name' => 'SuperUpdated',
            'last_name' => 'AdminUpdated',
            'position' => 'Lead Admin',
            'is_active' => false,
            'role' => 'employee',
        ]);

        $response->assertStatus(200);

        $this->superAdmin->refresh();

        $this->assertEquals('SuperUpdated', $this->superAdmin->first_name);
        $this->assertEquals('Lead Admin', $this->superAdmin->position);
        $this->assertTrue($this->superAdmin->is_active);
        $this->assertTrue($this->superAdmin->hasRole('super_admin'));
    }

    public function test_user_update_records_activity_log_and_account_history(): void
    {
        $response = $this->putJson("/api/users/{$this->targetUser->id}", [
            'position' => 'Division Officer',
            'is_active' => false,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('account_history_events', [
            'subject_user_id' => $this->targetUser->id,
            'actor_user_id' => $this->superAdmin->id,
            'event_type' => 'account.deactivated',
        ]);

        $this->assertDatabaseHas('activity_events', [
            'actor_user_id' => $this->superAdmin->id,
            'resource_type' => 'User',
            'resource_id' => $this->targetUser->id,
            'activity_type' => 'account.deactivated',
        ]);
    }
}
