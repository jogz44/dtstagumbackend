<?php

namespace Tests\Feature;

use App\Console\Commands\SyncPmisEmployees;
use App\Models\Division;
use App\Models\Office;
use App\Models\PmisEmployee;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\AdministratorProtectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserAccessUpdateApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $targetUser;

    protected Office $office;

    protected Role $adminRole;

    protected Role $receiverRole;

    protected Role $supervisorRole;

    private function seedRole(string $name, string $displayName): Role
    {
        $roleId = DB::table('roles')->where('name', $name)->where('guard_name', 'web')->value('id');
        if (! $roleId) {
            $roleId = (string) Str::uuid();
            DB::table('roles')->insert([
                'id' => $roleId,
                'name' => $name,
                'guard_name' => 'web',
                'display_name' => $displayName,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return Role::where('id', $roleId)->first();
    }

    private function seedPermission(string $name, string $groupName, string $description): Permission
    {
        $permId = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');
        if (! $permId) {
            $permId = (string) Str::uuid();
            DB::table('permissions')->insert([
                'id' => $permId,
                'name' => $name,
                'guard_name' => 'web',
                'group_name' => $groupName,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return Permission::where('id', $permId)->first();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRole = $this->seedRole('admin', 'Administrator');
        $this->receiverRole = $this->seedRole('receiver', 'Receiver');
        $this->supervisorRole = $this->seedRole('supervisor', 'Supervisor');

        $this->seedPermission('users.manage', 'Users', 'Manage user accounts');

        $this->office = Office::create(['name' => 'City Information Office', 'code' => 'CIO']);

        $this->adminUser = User::create([
            'username' => 'superadmin',
            'password' => bcrypt('password'),
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'office_id' => $this->office->id,
            'birthdate' => '1990-01-01',
            'is_active' => true,
        ]);
        $this->adminUser->assignRole('admin');

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
        $this->targetUser->assignRole('receiver');

        Sanctum::actingAs($this->adminUser);
    }

    public function test_authorized_admin_can_update_role_and_status(): void
    {
        $response = $this->patchJson("/api/users/{$this->targetUser->id}/access", [
            'role_id' => (string) $this->supervisorRole->id,
            'is_active' => true,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.role_id', $this->supervisorRole->id);
        $response->assertJsonPath('data.role', 'supervisor');
        $response->assertJsonPath('data.role_label', 'Supervisor');

        $this->targetUser->refresh();
        $this->assertTrue($this->targetUser->hasRole('supervisor'));
        $this->assertTrue($this->targetUser->is_active);
    }

    public function test_updating_role_preserves_permission_overrides(): void
    {
        $this->seedPermission('documents.view', 'Documents', 'View documents');
        UserPermissionOverride::create([
            'user_id' => $this->targetUser->id,
            'permission_name' => 'documents.view',
            'effect' => 'grant',
        ]);

        $this->patchJson("/api/users/{$this->targetUser->id}/access", [
            'role_id' => (string) $this->supervisorRole->id,
        ])->assertOk();

        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $this->targetUser->id,
            'permission_name' => 'documents.view',
            'effect' => 'grant',
        ]);
        $this->assertTrue($this->targetUser->fresh()->hasPermissionTo('documents.view'));
    }

    public function test_access_update_accepts_boolean_is_active(): void
    {
        $response = $this->patchJson("/api/users/{$this->targetUser->id}/access", [
            'role_id' => (string) $this->receiverRole->id,
            'is_active' => false,
        ]);

        $response->assertStatus(200);
        $this->targetUser->refresh();
        $this->assertFalse($this->targetUser->is_active);
        $this->assertTrue($this->targetUser->trashed());
    }

    public function test_access_update_rejects_mssms_managed_fields(): void
    {
        $receiverRoleId = (string) $this->receiverRole->id;
        $forbiddenPayloads = [
            ['first_name' => 'FAKE', 'role_id' => $receiverRoleId, 'is_active' => true],
            ['last_name' => 'FAKE', 'role_id' => $receiverRoleId, 'is_active' => true],
            ['employee_id' => 'EMP-999', 'role_id' => $receiverRoleId, 'is_active' => true],
            ['contact_number' => '09123456789', 'role_id' => $receiverRoleId, 'is_active' => true],
            ['office_id' => $this->office->id, 'role_id' => $receiverRoleId, 'is_active' => true],
            ['division_id' => (string) Str::uuid(), 'role_id' => $receiverRoleId, 'is_active' => true],
            ['position' => 'NEW POSITION', 'role_id' => $receiverRoleId, 'is_active' => true],
            ['email' => 'fake@example.com', 'role_id' => $receiverRoleId, 'is_active' => true],
        ];

        foreach ($forbiddenPayloads as $payload) {
            $response = $this->patchJson("/api/users/{$this->targetUser->id}/access", $payload);
            $response->assertStatus(422);
        }
    }

    public function test_unauthorized_users_receive_403(): void
    {
        Sanctum::actingAs($this->targetUser);

        $response = $this->patchJson("/api/users/{$this->adminUser->id}/access", [
            'role_id' => (string) $this->receiverRole->id,
            'is_active' => true,
        ]);

        $response->assertStatus(403);
    }

    public function test_ordinary_user_cannot_open_or_mutate_another_users_access_details(): void
    {
        Sanctum::actingAs($this->targetUser);

        $this->getJson("/api/users/{$this->adminUser->id}")->assertForbidden();
        $this->patchJson("/api/users/{$this->targetUser->id}/access", [
            'role_id' => (string) $this->supervisorRole->id,
        ])->assertForbidden();
    }

    public function test_self_service_user_can_update_only_their_own_username(): void
    {
        Sanctum::actingAs($this->targetUser);

        $this->putJson("/api/users/{$this->targetUser->id}", [
            'username' => 'john.self',
        ])->assertOk();

        $this->assertSame('john.self', $this->targetUser->fresh()->username);

        $this->putJson("/api/users/{$this->targetUser->id}", [
            'role_id' => (string) $this->supervisorRole->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['role_id']);

        $this->putJson("/api/users/{$this->targetUser->id}", [
            'is_active' => false,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['is_active']);
    }

    public function test_non_admin_with_management_permission_cannot_update_access(): void
    {
        $this->seedPermission('users.permissions.manage', 'Users', 'Manage user access settings');
        $this->targetUser->givePermissionTo('users.permissions.manage');

        Sanctum::actingAs($this->targetUser);

        $anotherUser = User::create([
            'username' => 'another.user',
            'password' => bcrypt('password'),
            'first_name' => 'Another',
            'last_name' => 'User',
            'is_active' => true,
        ]);
        $anotherUser->assignRole('receiver');

        $response = $this->patchJson("/api/users/{$anotherUser->id}/access", [
            'role_id' => (string) $this->supervisorRole->id,
            'is_active' => true,
        ]);

        $response->assertForbidden();
        $anotherUser->refresh();
        $this->assertTrue($anotherUser->hasRole('receiver'));
    }

    public function test_invalid_role_returns_422(): void
    {
        $response = $this->patchJson("/api/users/{$this->targetUser->id}/access", [
            'role_id' => (string) Str::uuid(),
            'is_active' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['role_id']);
    }

    public function test_legacy_role_is_rejected_even_when_it_exists(): void
    {
        $legacyRole = $this->seedRole('employee', 'Staff');

        $this->patchJson("/api/users/{$this->targetUser->id}/access", [
            'role_id' => (string) $legacyRole->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['role_id']);
    }

    public function test_self_deactivation_is_blocked_with_422(): void
    {
        $response = $this->patchJson("/api/users/{$this->adminUser->id}/access", [
            'role_id' => (string) $this->adminRole->id,
            'is_active' => false,
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'You cannot deactivate your own account.']);

        $this->adminUser->refresh();
        $this->assertTrue($this->adminUser->is_active);
    }

    public function test_administrator_cannot_demote_another_administrator(): void
    {
        $admin2 = User::create([
            'username' => 'admin2',
            'password' => bcrypt('password'),
            'first_name' => 'Second',
            'last_name' => 'Admin',
            'is_active' => true,
        ]);
        $admin2->assignRole('admin');

        Sanctum::actingAs($admin2);

        $response = $this->patchJson("/api/users/{$this->adminUser->id}/access", [
            'role_id' => (string) $this->receiverRole->id,
            'is_active' => true,
        ]);

        $response->assertForbidden();
        $this->assertTrue($this->adminUser->fresh()->hasRole('admin'));
    }

    public function test_access_update_records_audits(): void
    {
        $response = $this->patchJson("/api/users/{$this->targetUser->id}/access", [
            'role_id' => (string) $this->supervisorRole->id,
            'is_active' => false,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('account_history_events', [
            'subject_user_id' => $this->targetUser->id,
            'actor_user_id' => $this->adminUser->id,
        ]);

        $this->assertDatabaseHas('activity_events', [
            'actor_user_id' => $this->adminUser->id,
            'resource_type' => 'User',
            'resource_id' => $this->targetUser->id,
        ]);
    }

    public function test_authorized_admin_can_update_username(): void
    {
        $response = $this->patchJson("/api/users/{$this->targetUser->id}/access", [
            'username' => 'jdoeapo',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.username', 'jdoeapo');

        $this->assertDatabaseHas('users', [
            'id' => $this->targetUser->id,
            'username' => 'jdoeapo',
        ]);
    }

    public function test_administrator_cannot_update_another_administrator_access(): void
    {
        $admin2 = User::create([
            'username' => 'admin2',
            'password' => bcrypt('password'),
            'first_name' => 'Second',
            'last_name' => 'Admin',
            'is_active' => true,
        ]);
        $admin2->assignRole('admin');

        $this->patchJson("/api/users/{$admin2->id}/access", [
            'role_id' => (string) $this->receiverRole->id,
        ])->assertForbidden();

        $this->assertTrue($admin2->fresh()->hasRole('admin'));
    }

    public function test_duplicate_username_returns_validation_error(): void
    {
        $response = $this->patchJson("/api/users/{$this->targetUser->id}/access", [
            'username' => $this->adminUser->username,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['username']);
    }

    public function test_soft_deleted_user_is_restored_when_reactivated(): void
    {
        $this->targetUser->forceFill(['is_active' => false])->save();
        $this->targetUser->delete();

        $this->patchJson("/api/users/{$this->targetUser->id}/access", [
            'role_id' => (string) $this->receiverRole->id,
            'is_active' => true,
        ])->assertOk()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.deleted_at', null);

        $restoredUser = User::withTrashed()->findOrFail($this->targetUser->id);
        $this->assertTrue($restoredUser->is_active);
        $this->assertFalse($restoredUser->trashed());
        $this->assertDatabaseHas('account_history_events', [
            'subject_user_id' => $this->targetUser->id,
            'event_type' => 'account.reactivated',
        ]);
    }

    public function test_administrator_protection_runs_inside_the_mutation_transaction(): void
    {
        $protection = \Mockery::mock(AdministratorProtectionService::class)->makePartial();
        $protection->shouldReceive('lockAndEnsureTransition')
            ->once()
            ->andReturnUsing(function (User $actor, User $targetUser): User {
                $this->assertSame($this->adminUser->id, $actor->id);
                $this->assertGreaterThan(0, DB::transactionLevel());

                return User::withTrashed()->with('roles')->findOrFail($targetUser->id);
            });
        $this->app->instance(AdministratorProtectionService::class, $protection);

        $this->patchJson("/api/users/{$this->targetUser->id}/access", [
            'role_id' => (string) $this->supervisorRole->id,
        ])->assertOk();
    }

    public function test_mssms_sync_updates_identity_fields_without_overwriting_username_or_role(): void
    {
        $pmisEmployee = PmisEmployee::create([
            'pmis_no' => '009371',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'birth_date' => '1995-05-15',
            'office' => 'City Information Office',
            'designation' => 'Senior Officer',
            'employment_status' => 'Permanent',
        ]);

        $this->targetUser->update([
            'username' => 'jdoeapo',
            'pmis_employee_id' => $pmisEmployee->id,
        ]);

        // Simulate MSSMS profile change: First Name John -> Jonathan, Office -> HR Office
        $newOffice = Office::create(['name' => 'Human Resource Management Office', 'code' => 'HRMO']);
        $newDivision = Division::create([
            'office_id' => $newOffice->id,
            'name' => 'Administrative Support Section',
            'code' => 'HRMO-ADMIN',
        ]);
        $pmisEmployee->update([
            'first_name' => 'Jonathan',
            'last_name' => 'Doe',
            'office' => 'Human Resource Management Office',
            'division' => 'Administrative Support Section',
            'designation' => 'Chief Officer',
        ]);

        $permission = $this->seedPermission('documents.view', 'Documents', 'View documents');
        UserPermissionOverride::create([
            'user_id' => $this->targetUser->id,
            'permission_name' => $permission->name,
            'effect' => 'grant',
        ]);
        $passwordBeforeSync = $this->targetUser->password;

        // Run sync method on User / SyncPmisEmployees
        SyncPmisEmployees::syncLinkedUserIdentity($this->targetUser->fresh());

        $this->targetUser->refresh();

        // Verify MSSMS-managed fields updated
        $this->assertEquals('Jonathan', $this->targetUser->first_name);
        $this->assertEquals('Chief Officer', $this->targetUser->position);
        $this->assertEquals($newOffice->id, $this->targetUser->office_id);
        $this->assertEquals($newDivision->id, $this->targetUser->division_id);

        // Verify locally managed fields were NOT overwritten
        $this->assertEquals('jdoeapo', $this->targetUser->username);
        $this->assertTrue($this->targetUser->hasRole('receiver'));
        $this->assertTrue($this->targetUser->is_active);
        $this->assertSame($passwordBeforeSync, $this->targetUser->password);
        $this->assertDatabaseHas('user_permission_overrides', [
            'user_id' => $this->targetUser->id,
            'permission_name' => $permission->name,
        ]);
    }

    public function test_show_endpoint_returns_target_user_identity_and_role_id(): void
    {
        $response = $this->getJson("/api/users/{$this->targetUser->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $this->targetUser->id);
        $response->assertJsonPath('data.first_name', 'John');
        $response->assertJsonPath('data.last_name', 'Doe');
        $response->assertJsonPath('data.office', 'City Information Office');
        $response->assertJsonPath('data.division', null);
        $response->assertJsonPath('data.role_id', (string) $this->receiverRole->id);
        $response->assertJsonPath('data.role', 'receiver');
        $response->assertJsonPath('data.role_label', 'Receiver');
    }

    public function test_show_endpoint_uses_scalar_pmis_fallbacks(): void
    {
        $pmisEmployee = PmisEmployee::create([
            'pmis_no' => 'PMIS-RESOURCE-1',
            'office' => 'PMIS Office',
            'division' => 'PMIS Division',
        ]);
        $this->targetUser->forceFill([
            'office_id' => null,
            'division_id' => null,
            'pmis_employee_id' => $pmisEmployee->id,
        ])->save();

        $this->getJson("/api/users/{$this->targetUser->id}")
            ->assertOk()
            ->assertJsonPath('data.office', 'PMIS Office')
            ->assertJsonPath('data.division', 'PMIS Division');
    }

    public function test_roles_contract_is_preserved_and_options_are_assignable_only(): void
    {
        $legacyRole = $this->seedRole('employee', 'Staff');

        $this->getJson('/api/roles')
            ->assertOk()
            ->assertJsonStructure(['roles', 'permissions']);

        $response = $this->getJson('/api/roles/options')->assertOk();
        $response->assertExactJson([
            ['id' => $this->adminRole->id, 'name' => 'admin', 'label' => 'Administrator'],
            ['id' => $this->supervisorRole->id, 'name' => 'supervisor', 'label' => 'Supervisor'],
            ['id' => $this->receiverRole->id, 'name' => 'receiver', 'label' => 'Receiver'],
        ]);
        $this->assertNotNull($legacyRole->id);
    }
}
