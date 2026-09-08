<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EffectivePermissionsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $supervisor;

    private User $peer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = $this->makeUser('manager', 'admin');
        $this->supervisor = $this->makeUser('supervisor.one', 'supervisor');
        $this->peer = $this->makeUser('supervisor.two', 'supervisor');
        Sanctum::actingAs($this->admin);
    }

    private function makeUser(string $username, string $role): User
    {
        $user = User::create([
            'username' => $username, 'password' => bcrypt('password'),
            'first_name' => 'Test', 'last_name' => 'User', 'is_active' => true,
        ]);
        $user->assignRole(Role::findByName($role, 'web'));

        return $user;
    }

    private function overrides(User $user, array $overrides): TestResponse
    {
        return $this->patchJson("/api/users/{$user->id}/access", ['permission_overrides' => $overrides]);
    }

    public function test_admin_can_grant_deny_and_reset_individual_permissions(): void
    {
        $this->assertTrue($this->supervisor->hasPermissionTo('reports.export'));
        $this->overrides($this->supervisor, [
            ['permission_name' => 'reports.export', 'effect' => 'deny'],
            ['permission_name' => 'users.manage', 'effect' => 'grant'],
        ])->assertOk();
        $user = $this->supervisor->fresh();
        $this->assertFalse($user->hasPermissionTo('reports.export'));
        $this->assertTrue($user->hasPermissionTo('users.manage'));
        $this->assertTrue($this->peer->fresh()->hasPermissionTo('reports.export'));
        $this->assertFalse($this->peer->fresh()->hasPermissionTo('users.manage'));
        $this->overrides($this->supervisor, [])->assertOk();
        $this->assertTrue($this->supervisor->fresh()->hasPermissionTo('reports.export'));
        $this->assertFalse($this->supervisor->fresh()->hasPermissionTo('users.manage'));
        $this->assertDatabaseMissing('user_permission_overrides', ['user_id' => $this->supervisor->id]);
    }

    public function test_role_defaults_change_inheritance_but_preserve_explicit_grants_and_denials(): void
    {
        $this->overrides($this->supervisor, [
            ['permission_name' => 'reports.export', 'effect' => 'deny'],
            ['permission_name' => 'reports.view', 'effect' => 'grant'],
        ])->assertOk();
        $role = Role::findByName('supervisor', 'web');
        $this->putJson("/api/roles/{$role->id}/permissions", ['permissions' => ['reports.export']])->assertOk();
        $this->assertTrue($this->peer->fresh()->hasPermissionTo('reports.export'));
        $this->assertFalse($this->peer->fresh()->hasPermissionTo('reports.view'));
        $this->assertFalse($this->supervisor->fresh()->hasPermissionTo('reports.export'));
        $this->assertTrue($this->supervisor->fresh()->hasPermissionTo('reports.view'));
        $this->putJson("/api/roles/{$role->id}/permissions", ['permissions' => []])->assertOk();
        $this->assertFalse($this->peer->fresh()->hasPermissionTo('reports.export'));
    }

    public function test_non_admin_cannot_change_roles_defaults_or_overrides_even_with_management_grants(): void
    {
        $this->overrides($this->supervisor, [
            ['permission_name' => 'users.manage', 'effect' => 'grant'],
            ['permission_name' => 'users.permissions.manage', 'effect' => 'grant'],
        ])->assertOk();
        Sanctum::actingAs($this->supervisor->fresh());
        $role = Role::findByName('receiver', 'web');
        $this->patchJson("/api/users/{$this->peer->id}/access", ['role_id' => $role->id])->assertForbidden();
        $this->putJson("/api/roles/{$role->id}/permissions", ['permissions' => []])->assertForbidden();
        $this->overrides($this->peer, [])->assertForbidden();
        $this->overrides($this->supervisor, [['permission_name' => 'reports.export', 'effect' => 'grant']])->assertForbidden();
        $this->postJson('/api/users', ['role' => 'admin'])->assertForbidden();
    }

    public function test_export_and_document_endpoints_enforce_effective_permissions(): void
    {
        $this->overrides($this->supervisor, [
            ['permission_name' => 'reports.export', 'effect' => 'deny'],
            ['permission_name' => 'can_add_documents', 'effect' => 'deny'],
        ])->assertOk();
        Sanctum::actingAs($this->supervisor->fresh());
        $this->getJson('/api/reports/export')->assertForbidden();
        $this->postJson('/api/documents', [])->assertForbidden();
        $this->getJson('/api/reports')->assertOk();
        Sanctum::actingAs($this->peer->fresh());
        $this->getJson('/api/reports/export')->assertOk();
    }

    public function test_profile_and_gate_agree_about_effective_permissions(): void
    {
        $this->overrides($this->supervisor, [['permission_name' => 'reports.export', 'effect' => 'deny']])->assertOk();
        Sanctum::actingAs($this->supervisor->fresh());
        $response = $this->getJson('/api/me')->assertOk();
        $this->assertNotContains('reports.export', $response->json('data.effective_permissions'));
        $this->assertFalse($this->supervisor->fresh()->can('reports.export'));
        $matrix = collect($response->json('data.permission_matrix'))->keyBy('name');
        $this->assertSame('deny', $matrix['reports.export']['override']);
        $this->assertTrue($matrix['reports.export']['role_default']);
    }

    public function test_administrator_management_access_and_last_admin_are_protected(): void
    {
        $this->overrides($this->admin, [['permission_name' => 'users.manage', 'effect' => 'deny']])->assertUnprocessable();
        $role = Role::findByName('admin', 'web');
        $this->putJson("/api/roles/{$role->id}/permissions", ['permissions' => []])->assertUnprocessable();
        $this->patchJson("/api/users/{$this->admin->id}/access", ['role_id' => Role::findByName('receiver', 'web')->id])->assertUnprocessable();
        $this->overrides($this->admin, [['permission_name' => 'reports.export', 'effect' => 'deny']])->assertOk();
        $this->assertFalse($this->admin->fresh()->can('reports.export'));
    }

    public function test_username_does_not_confer_administrator_privileges(): void
    {
        $user = $this->makeUser('admin', 'receiver');
        Sanctum::actingAs($user);
        $this->overrides($this->peer, [])->assertForbidden();
        $role = Role::findByName('receiver', 'web');
        $this->putJson("/api/roles/{$role->id}/permissions", ['permissions' => []])->assertForbidden();
    }

    public function test_unknown_and_duplicate_overrides_are_rejected(): void
    {
        $this->overrides($this->supervisor, [['permission_name' => 'unknown', 'effect' => 'grant']])->assertUnprocessable();
        $this->overrides($this->supervisor, [
            ['permission_name' => 'reports.view', 'effect' => 'grant'],
            ['permission_name' => 'reports.view', 'effect' => 'deny'],
        ])->assertUnprocessable();
    }
}
