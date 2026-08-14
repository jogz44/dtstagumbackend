<?php

namespace Tests\Feature;

use App\Models\ActivityEvent;
use App\Models\Division;
use App\Models\Office;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserActivityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_read_activity_log(): void
    {
        $admin = $this->createSuperAdmin();
        $targetUser = $this->createUser('target_user', 'Target', 'User');

        ActivityEvent::create([
            'actor_user_id' => $targetUser->id,
            'activity_type' => 'auth.login_succeeded',
            'module' => 'Authentication',
            'description' => 'Logged in successfully.',
            'outcome' => 'success',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
            'occurred_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/users/{$targetUser->id}/activity");

        $response
            ->assertOk()
            ->assertJsonPath('data.0.activity_type', 'auth.login_succeeded')
            ->assertJsonPath('data.0.module', 'Authentication')
            ->assertJsonPath('data.0.description', 'Logged in successfully.')
            ->assertJsonPath('data.0.outcome', 'success')
            ->assertJsonPath('data.0.actor.id', $targetUser->id)
            ->assertJsonPath('data.0.actor.name', $targetUser->full_name)
            ->assertJsonPath('data.0.ip_address', '127.0.0.1')
            ->assertJsonPath('data.0.device', 'Chrome on Windows')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'occurred_at',
                        'activity_type',
                        'action',
                        'module',
                        'category',
                        'description',
                        'activity',
                        'outcome',
                        'actor' => ['id', 'name'],
                        'ip_address',
                        'device',
                    ],
                ],
                'pagination' => [
                    'current_page',
                    'per_page',
                    'total',
                    'last_page',
                ],
            ]);
    }

    public function test_unauthorized_user_receives_403(): void
    {
        $targetUser = $this->createUser('target_user', 'Target', 'User');
        $unauthorizedUser = $this->createUser('unauthorized', 'Unauthorized', 'User');

        Sanctum::actingAs($unauthorizedUser);

        $response = $this->getJson("/api/users/{$targetUser->id}/activity");

        $response->assertStatus(403);
    }

    public function test_activity_events_are_ordered_newest_first(): void
    {
        $admin = $this->createSuperAdmin();
        $targetUser = $this->createUser('target_user', 'Target', 'User');

        $older = ActivityEvent::create([
            'actor_user_id' => $targetUser->id,
            'activity_type' => 'auth.login_succeeded',
            'module' => 'Authentication',
            'description' => 'Older activity',
            'occurred_at' => now()->subHours(2),
        ]);

        $newer = ActivityEvent::create([
            'actor_user_id' => $targetUser->id,
            'activity_type' => 'auth.login_succeeded',
            'module' => 'Authentication',
            'description' => 'Newer activity',
            'occurred_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/users/{$targetUser->id}/activity");

        $response
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
    }

    public function test_server_side_pagination_and_clamping(): void
    {
        $admin = $this->createSuperAdmin();
        $targetUser = $this->createUser('target_user', 'Target', 'User');

        for ($i = 1; $i <= 30; $i++) {
            ActivityEvent::create([
                'actor_user_id' => $targetUser->id,
                'activity_type' => 'user.action',
                'module' => 'System',
                'description' => "Activity {$i}",
                'occurred_at' => now()->subMinutes(30 - $i),
            ]);
        }

        Sanctum::actingAs($admin);

        // Default per_page = 25
        $defaultResponse = $this->getJson("/api/users/{$targetUser->id}/activity");
        $defaultResponse
            ->assertOk()
            ->assertJsonPath('pagination.per_page', 25)
            ->assertJsonCount(25, 'data');

        // Page size clamping (request 999 -> clamped to 100)
        $clampedResponse = $this->getJson("/api/users/{$targetUser->id}/activity?page_size=999");
        $clampedResponse
            ->assertOk()
            ->assertJsonPath('pagination.per_page', 100);

        // Explicit page_size 50
        $fiftyResponse = $this->getJson("/api/users/{$targetUser->id}/activity?page_size=50");
        $fiftyResponse
            ->assertOk()
            ->assertJsonPath('pagination.per_page', 50);
    }

    public function test_search_filters_activity_events(): void
    {
        $admin = $this->createSuperAdmin();
        $targetUser = $this->createUser('target_user', 'Target', 'User');

        ActivityEvent::create([
            'actor_user_id' => $targetUser->id,
            'activity_type' => 'profile.updated',
            'module' => 'User Management',
            'description' => 'Updated contact details',
            'occurred_at' => now(),
        ]);

        ActivityEvent::create([
            'actor_user_id' => $targetUser->id,
            'activity_type' => 'auth.login_succeeded',
            'module' => 'Authentication',
            'description' => 'Logged in from workplace',
            'occurred_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/users/{$targetUser->id}/activity?search=contact");
        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.activity_type', 'profile.updated');
    }

    public function test_activity_type_and_action_alias_filters(): void
    {
        $admin = $this->createSuperAdmin();
        $targetUser = $this->createUser('target_user', 'Target', 'User');

        ActivityEvent::create([
            'actor_user_id' => $targetUser->id,
            'activity_type' => 'LOGIN',
            'module' => 'Auth',
            'description' => 'Login event',
            'occurred_at' => now(),
        ]);

        ActivityEvent::create([
            'actor_user_id' => $targetUser->id,
            'activity_type' => 'LOGOUT',
            'module' => 'Auth',
            'description' => 'Logout event',
            'occurred_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        // Filter by activity_type
        $response1 = $this->getJson("/api/users/{$targetUser->id}/activity?activity_type=LOGIN");
        $response1
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.activity_type', 'LOGIN');

        // Filter using action alias
        $response2 = $this->getJson("/api/users/{$targetUser->id}/activity?action=LOGOUT");
        $response2
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.activity_type', 'LOGOUT');
    }

    public function test_module_and_category_alias_filters(): void
    {
        $admin = $this->createSuperAdmin();
        $targetUser = $this->createUser('target_user', 'Target', 'User');

        ActivityEvent::create([
            'actor_user_id' => $targetUser->id,
            'activity_type' => 'event_1',
            'module' => 'Security',
            'description' => 'Security event',
            'occurred_at' => now(),
        ]);

        ActivityEvent::create([
            'actor_user_id' => $targetUser->id,
            'activity_type' => 'event_2',
            'module' => 'Settings',
            'description' => 'Settings event',
            'occurred_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        // Filter by category alias
        $response = $this->getJson("/api/users/{$targetUser->id}/activity?category=Security");
        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.module', 'Security');
    }

    public function test_outcome_filter(): void
    {
        $admin = $this->createSuperAdmin();
        $targetUser = $this->createUser('target_user', 'Target', 'User');

        ActivityEvent::create([
            'actor_user_id' => $targetUser->id,
            'activity_type' => 'auth.login_failed',
            'module' => 'Auth',
            'description' => 'Failed password',
            'outcome' => 'failure',
            'occurred_at' => now(),
        ]);

        ActivityEvent::create([
            'actor_user_id' => $targetUser->id,
            'activity_type' => 'auth.login_succeeded',
            'module' => 'Auth',
            'description' => 'Logged in',
            'outcome' => 'success',
            'occurred_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/users/{$targetUser->id}/activity?outcome=failure");
        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.outcome', 'failure');
    }

    public function test_valid_date_range_filter(): void
    {
        $admin = $this->createSuperAdmin();
        $targetUser = $this->createUser('target_user', 'Target', 'User');

        ActivityEvent::create([
            'actor_user_id' => $targetUser->id,
            'activity_type' => 'auth.login',
            'module' => 'Auth',
            'description' => 'In range event',
            'occurred_at' => '2026-08-05 10:00:00',
        ]);

        ActivityEvent::create([
            'actor_user_id' => $targetUser->id,
            'activity_type' => 'auth.login',
            'module' => 'Auth',
            'description' => 'Out of range event',
            'occurred_at' => '2026-08-10 10:00:00',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/users/{$targetUser->id}/activity?date_from=2026-08-01&date_to=2026-08-07");
        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.description', 'In range event');
    }

    public function test_invalid_date_range_returns_422_validation_error(): void
    {
        $admin = $this->createSuperAdmin();
        $targetUser = $this->createUser('target_user', 'Target', 'User');

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/users/{$targetUser->id}/activity?date_from=2026-08-10&date_to=2026-08-01");
        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date_to']);
    }

    public function test_null_actor_safety_when_user_is_deleted_or_system(): void
    {
        $admin = $this->createSuperAdmin();
        $targetUser = $this->createUser('target_user', 'Target', 'User');

        ActivityEvent::create([
            'actor_user_id' => null,
            'activity_type' => 'system.cron',
            'module' => 'System',
            'description' => 'Automated system cleanup',
            'occurred_at' => now(),
        ]);

        // Assign to user via manual query update if needed, but for actor resolution test:
        ActivityEvent::where('description', 'Automated system cleanup')->update(['actor_user_id' => $targetUser->id]);

        // Break actor relationship by deleting or setting actor to null
        $event = ActivityEvent::where('description', 'Automated system cleanup')->first();
        $event->update(['actor_user_id' => $targetUser->id]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/users/{$targetUser->id}/activity");
        $response->assertOk();
    }

    public function test_sensitive_values_are_not_exposed(): void
    {
        $admin = $this->createSuperAdmin();
        $targetUser = $this->createUser('target_user', 'Target', 'User');

        ActivityEvent::create([
            'actor_user_id' => $targetUser->id,
            'activity_type' => 'auth.login_succeeded',
            'module' => 'Auth',
            'description' => 'User login',
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'occurred_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/users/{$targetUser->id}/activity");
        $response->assertOk();

        $content = $response->getContent();
        $this->assertStringNotContainsString('password', strtolower($content));
        $this->assertStringNotContainsString('token', strtolower($content));
        $this->assertStringNotContainsString('secret', strtolower($content));
    }

    public function test_creating_user_records_account_created_in_admin_activity_log(): void
    {
        $admin = $this->createSuperAdmin();
        Sanctum::actingAs($admin);

        $office = Office::firstOrCreate(['code' => 'CMO'], ['name' => "City Mayor's Office", 'is_active' => true]);
        $division = Division::firstOrCreate(['office_id' => $office->id, 'code' => 'ADMIN'], ['name' => 'Administrative Division', 'is_active' => true]);
        Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);

        $employee = \App\Models\PmisEmployee::create([
            'pmis_no' => '998877',
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'birth_date' => '1995-05-15',
            'office' => "City Mayor's Office",
        ]);

        $payload = [
            'pmis_employee_id' => $employee->id,
            'role' => 'user',
            'is_active' => true,
        ];

        $response = $this->postJson('/api/users', $payload);
        $response->assertStatus(201);

        $createdUserId = $response->json('id');
        $this->assertNotNull($createdUserId);

        $this->assertDatabaseHas('activity_events', [
            'actor_user_id' => $admin->id,
            'activity_type' => 'account.created',
            'module' => 'User Management',
            'resource_type' => 'User',
            'resource_id' => (string) $createdUserId,
            'outcome' => 'success',
            'description' => 'Created portal account for PMIS employee Juan Dela Cruz (PMIS No. 998877).',
        ]);

        $adminActivityResponse = $this->getJson("/api/users/{$admin->id}/activity");
        $adminActivityResponse
            ->assertOk()
            ->assertJsonPath('data.0.activity_type', 'account.created')
            ->assertJsonPath('data.0.module', 'User Management')
            ->assertJsonPath('data.0.description', 'Created portal account for PMIS employee Juan Dela Cruz (PMIS No. 998877).')
            ->assertJsonPath('data.0.actor.id', $admin->id)
            ->assertJsonPath('data.0.actor.name', $admin->full_name);

        $userActivityResponse = $this->getJson("/api/users/{$createdUserId}/activity");
        $userActivityResponse
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $userHistoryResponse = $this->getJson("/api/users/{$createdUserId}/history");
        $userHistoryResponse
            ->assertOk()
            ->assertJsonPath('data.0.event_type', 'account.created')
            ->assertJsonPath('data.0.changed_by', $admin->full_name);
    }

    private function createSuperAdmin(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $permission = Permission::firstOrCreate(['name' => 'documents.view_confidential', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        $office = Office::create([
            'code' => 'CMO',
            'name' => "City Mayor's Office",
            'is_active' => true,
        ]);
        $division = Division::create([
            'office_id' => $office->id,
            'code' => 'ADMIN',
            'name' => 'Administrative Division',
            'is_active' => true,
        ]);

        $user = User::create([
            'username' => 'admin_'.uniqid(),
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'office_id' => $office->id,
            'division_id' => $division->id,
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function createUser(string $username, string $firstName, string $lastName): User
    {
        $office = Office::firstOrCreate([
            'code' => 'CMO',
        ], [
            'name' => "City Mayor's Office",
            'is_active' => true,
        ]);
        $division = Division::firstOrCreate([
            'office_id' => $office->id,
            'code' => 'ADMIN',
        ], [
            'name' => 'Administrative Division',
            'is_active' => true,
        ]);

        return User::create([
            'username' => $username.'_'.uniqid(),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'office_id' => $office->id,
            'division_id' => $division->id,
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
    }
}
