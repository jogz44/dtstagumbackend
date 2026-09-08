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

    protected User $adminUser;
    protected User $targetUser;
    protected Office $office;

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

        if (! DB::table('roles')->where('name', 'employee')->exists()) {
            DB::table('roles')->insert([
                'id' => (string) Str::uuid(),
                'name' => 'employee',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('permissions')->updateOrInsert(
            [
                'name' => 'users.manage',
                'guard_name' => 'web',
            ],
            [
                'id' => DB::table('permissions')->where('name', 'users.manage')->where('guard_name', 'web')->value('id') ?: (string) Str::uuid(),
                'group_name' => 'Users',
                'description' => 'Manage user accounts',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->office = Office::create(['name' => 'HR Office', 'code' => 'HRO']);

        $this->adminUser = User::create([
            'username' => 'superadmin',
            'password' => bcrypt('password'),
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'office_id' => $this->office->id,
            'birthdate' => '1990-01-01',
            'is_active' => true,
        ]);
        DB::table('model_has_roles')->insert([
            'role_id' => DB::table('roles')->where('name', 'admin')->where('guard_name', 'web')->value('id'),
            'model_type' => User::class,
            'model_id' => $this->adminUser->id,
        ]);

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
        DB::table('model_has_roles')->insert([
            'role_id' => DB::table('roles')->where('name', 'employee')->where('guard_name', 'web')->value('id'),
            'model_type' => User::class,
            'model_id' => $this->targetUser->id,
        ]);

        Sanctum::actingAs($this->adminUser);
    }

    public function test_user_update_allows_username_change(): void
    {
        $response = $this->putJson("/api/users/{$this->targetUser->id}", [
            'username' => 'john.doe.updated',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $this->targetUser->id,
            'username' => 'john.doe.updated',
        ]);
    }

    public function test_administrator_cannot_update_another_administrator(): void
    {
        $otherAdministrator = User::create([
            'username' => 'other.admin',
            'password' => bcrypt('password'),
            'first_name' => 'Other',
            'last_name' => 'Administrator',
            'is_active' => true,
        ]);
        $otherAdministrator->assignRole('admin');

        $this->putJson("/api/users/{$otherAdministrator->id}", [
            'username' => 'changed.admin',
        ])->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $otherAdministrator->id,
            'username' => 'other.admin',
        ]);
    }

    public function test_user_update_accepts_boolean_is_active(): void
    {
        $response = $this->putJson("/api/users/{$this->targetUser->id}", [
            'is_active' => false,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $this->targetUser->id,
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

    public function test_admin_deactivation_is_blocked_with_422(): void
    {
        $response = $this->putJson("/api/users/{$this->adminUser->id}", [
            'is_active' => false,
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'This account cannot be deactivated.']);

        $this->adminUser->refresh();
        $this->assertTrue($this->adminUser->is_active);
    }

    public function test_user_update_rejects_forbidden_fields(): void
    {
        $forbiddenPayloads = [
            ['first_name' => 'FAKE'],
            ['last_name' => 'FAKE'],
            ['employee_id' => 'EMP-999'],
            ['employeeId' => 'EMP-999'],
            ['birthdate' => '2000-01-01'],
            ['birth_date' => '2000-01-01'],
            ['office_id' => $this->office->id],
            ['officeId' => $this->office->id],
            ['division_id' => (string) Str::uuid()],
            ['divisionId' => (string) Str::uuid()],
            ['position' => 'NEW POSITION'],
            ['employment_status' => 'Casual'],
            ['role' => 'employee'],
            ['password' => 'newpassword123'],
            ['pmis_employee_id' => (string) Str::uuid()],
            ['email' => 'fake@example.com'],
            ['avatar_url' => 'https://example.com/avatar.png'],
        ];

        foreach ($forbiddenPayloads as $payload) {
            $response = $this->putJson("/api/users/{$this->targetUser->id}", $payload);
            $response->assertStatus(422);
        }
    }

    public function test_user_update_records_activity_log_and_account_history(): void
    {
        $response = $this->putJson("/api/users/{$this->targetUser->id}", [
            'username' => 'john.doe.logged',
            'is_active' => false,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('account_history_events', [
            'subject_user_id' => $this->targetUser->id,
            'actor_user_id' => $this->adminUser->id,
            'event_type' => 'account.deactivated',
        ]);

        $this->assertDatabaseHas('activity_events', [
            'actor_user_id' => $this->adminUser->id,
            'resource_type' => 'User',
            'resource_id' => $this->targetUser->id,
            'activity_type' => 'account.deactivated',
        ]);
    }
}
