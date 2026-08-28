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

class ActivityLogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_read_global_activity_logs(): void
    {
        $viewer = $this->createUserWithActivityLogAccess();
        $firstActor = $this->createUser('first_actor', 'First', 'Actor');
        $secondActor = $this->createUser('second_actor', 'Second', 'Actor');

        $older = ActivityEvent::create([
            'actor_user_id' => $firstActor->id,
            'activity_type' => 'account.created',
            'module' => 'User Management',
            'description' => 'Created user account for First Actor.',
            'outcome' => 'success',
            'resource_type' => 'User',
            'resource_id' => $firstActor->id,
            'occurred_at' => now()->subHours(2),
        ]);

        $newer = ActivityEvent::create([
            'actor_user_id' => $secondActor->id,
            'activity_type' => 'auth.login_succeeded',
            'module' => 'Authentication',
            'description' => 'Logged in to the system.',
            'outcome' => 'success',
            'occurred_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($viewer);

        $response = $this->getJson('/api/activity-logs');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.0.activity_type', 'auth.login_succeeded')
            ->assertJsonPath('data.0.module', 'Authentication')
            ->assertJsonPath('data.0.actor.display_name', $secondActor->full_name)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('pagination.per_page', 25)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'activity_type',
                        'module',
                        'description',
                        'outcome',
                        'occurred_at',
                        'actor' => ['id', 'display_name'],
                        'resource_type',
                        'resource_id',
                    ],
                ],
                'pagination' => [
                    'current_page',
                    'per_page',
                    'total',
                    'last_page',
                ],
                'meta' => [
                    'current_page',
                    'per_page',
                    'total',
                    'last_page',
                ],
            ]);

        $this->assertArrayNotHasKey('office_id', $response->json('data.0'));
        $this->assertArrayNotHasKey('division_id', $response->json('data.0'));
    }

    public function test_user_without_activity_log_permission_receives_403(): void
    {
        Sanctum::actingAs($this->createUser('regular_user', 'Regular', 'User'));

        $response = $this->getJson('/api/activity-logs');

        $response->assertStatus(403);
    }

    public function test_filters_by_activity_type_module_outcome_and_date_range(): void
    {
        $viewer = $this->createUserWithActivityLogAccess();
        $actor = $this->createUser('actor', 'Filter', 'Actor');

        ActivityEvent::create([
            'actor_user_id' => $actor->id,
            'activity_type' => 'auth.login_failed',
            'module' => 'Authentication',
            'description' => 'Failed login attempt.',
            'outcome' => 'failure',
            'occurred_at' => '2026-08-05 10:00:00',
        ]);

        ActivityEvent::create([
            'actor_user_id' => $actor->id,
            'activity_type' => 'account.created',
            'module' => 'User Management',
            'description' => 'Created user account.',
            'outcome' => 'success',
            'occurred_at' => '2026-08-10 10:00:00',
        ]);

        Sanctum::actingAs($viewer);

        $response = $this->getJson('/api/activity-logs?activity_type=auth.login_failed&module=Authentication&outcome=failure&date_from=2026-08-01&date_to=2026-08-07');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.activity_type', 'auth.login_failed')
            ->assertJsonPath('data.0.outcome', 'failure');
    }

    public function test_unknown_activity_type_returns_empty_results(): void
    {
        $viewer = $this->createUserWithActivityLogAccess();
        ActivityEvent::create([
            'actor_user_id' => $viewer->id,
            'activity_type' => 'auth.login_succeeded',
            'module' => 'Authentication',
            'description' => 'Logged in to the system.',
            'outcome' => 'success',
            'occurred_at' => now(),
        ]);

        Sanctum::actingAs($viewer);

        $response = $this->getJson('/api/activity-logs?activity_type=does.not.exist');

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_date_from_and_date_to_work_independently(): void
    {
        $viewer = $this->createUserWithActivityLogAccess();

        ActivityEvent::create([
            'actor_user_id' => $viewer->id,
            'activity_type' => 'system.old',
            'module' => 'System',
            'description' => 'Old event.',
            'occurred_at' => '2026-08-01 10:00:00',
        ]);

        ActivityEvent::create([
            'actor_user_id' => $viewer->id,
            'activity_type' => 'system.new',
            'module' => 'System',
            'description' => 'New event.',
            'occurred_at' => '2026-08-12 10:00:00',
        ]);

        Sanctum::actingAs($viewer);

        $fromResponse = $this->getJson('/api/activity-logs?date_from=2026-08-10');
        $fromResponse
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.activity_type', 'system.new');

        $toResponse = $this->getJson('/api/activity-logs?date_to=2026-08-01');
        $toResponse
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.activity_type', 'system.old');
    }

    public function test_search_matches_actor_name_and_safe_activity_fields(): void
    {
        $viewer = $this->createUserWithActivityLogAccess();
        $rodney = $this->createUser('rfrancisco', 'Rodney', 'Francisco');
        $admin = $this->createUser('admin_actor', 'Admin', 'Actor');

        ActivityEvent::create([
            'actor_user_id' => $rodney->id,
            'activity_type' => 'document.received',
            'module' => 'Documents',
            'description' => 'Uploaded a new document.',
            'outcome' => 'success',
            'occurred_at' => now(),
        ]);

        ActivityEvent::create([
            'actor_user_id' => $admin->id,
            'activity_type' => 'account.created',
            'module' => 'User Management',
            'description' => 'Created user account.',
            'outcome' => 'success',
            'occurred_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($viewer);

        $actorResponse = $this->getJson('/api/activity-logs?search=Rodney');
        $actorResponse
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.actor.display_name', $rodney->full_name);

        $descriptionResponse = $this->getJson('/api/activity-logs?search=Created');
        $descriptionResponse
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.activity_type', 'account.created');
    }

    public function test_invalid_date_range_returns_422(): void
    {
        Sanctum::actingAs($this->createUserWithActivityLogAccess());

        $response = $this->getJson('/api/activity-logs?date_from=2026-08-12&date_to=2026-08-01');

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date_to']);
    }

    public function test_pagination_defaults_and_clamps_page_size(): void
    {
        $viewer = $this->createUserWithActivityLogAccess();

        for ($i = 1; $i <= 30; $i++) {
            ActivityEvent::create([
                'actor_user_id' => $viewer->id,
                'activity_type' => 'system.event',
                'module' => 'System',
                'description' => "Activity {$i}",
                'occurred_at' => now()->subMinutes(30 - $i),
            ]);
        }

        Sanctum::actingAs($viewer);

        $defaultResponse = $this->getJson('/api/activity-logs');
        $defaultResponse
            ->assertOk()
            ->assertJsonPath('pagination.per_page', 25)
            ->assertJsonCount(25, 'data');

        $fiftyResponse = $this->getJson('/api/activity-logs?per_page=50');
        $fiftyResponse
            ->assertOk()
            ->assertJsonPath('pagination.per_page', 50)
            ->assertJsonCount(30, 'data');

        $clampedResponse = $this->getJson('/api/activity-logs?per_page=999');
        $clampedResponse
            ->assertOk()
            ->assertJsonPath('pagination.per_page', 100);
    }

    public function test_system_actor_is_safe_and_sensitive_values_are_omitted(): void
    {
        Sanctum::actingAs($this->createUserWithActivityLogAccess());

        ActivityEvent::create([
            'actor_user_id' => null,
            'activity_type' => 'system.cleanup',
            'module' => 'System',
            'description' => 'Automated system cleanup.',
            'outcome' => 'success',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Mozilla/5.0',
            'request_id' => 'request-secret',
            'session_id' => 'session-secret',
            'occurred_at' => now(),
        ]);

        $response = $this->getJson('/api/activity-logs?search=system');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.actor.id', null)
            ->assertJsonPath('data.0.actor.display_name', 'System');

        $content = strtolower($response->getContent());
        $this->assertStringNotContainsString('password', $content);
        $this->assertStringNotContainsString('token', $content);
        $this->assertStringNotContainsString('session-secret', $content);
        $this->assertStringNotContainsString('request-secret', $content);
        $this->assertStringNotContainsString('mozilla', $content);
    }

    public function test_soft_deleted_actor_remains_displayable(): void
    {
        $viewer = $this->createUserWithActivityLogAccess();
        $actor = $this->createUser('deleted_actor', 'Deleted', 'Actor');

        ActivityEvent::create([
            'actor_user_id' => $actor->id,
            'activity_type' => 'account.deleted',
            'module' => 'User Management',
            'description' => 'Deleted user account for Disposable User.',
            'outcome' => 'success',
            'occurred_at' => now(),
        ]);

        $actor->delete();

        Sanctum::actingAs($viewer);

        $response = $this->getJson('/api/activity-logs');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.actor.id', $actor->id)
            ->assertJsonPath('data.0.actor.display_name', 'Deleted Actor');
    }

    private function createUserWithActivityLogAccess(): User
    {
        $permission = Permission::firstOrCreate(['name' => 'activity_logs.view', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'activity_viewer', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        $user = $this->createUser('activity_viewer', 'Activity', 'Viewer');
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
