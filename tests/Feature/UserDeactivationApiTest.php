<?php

namespace Tests\Feature;

use App\Models\AccountHistoryEvent;
use App\Models\ActivityEvent;
use App\Models\Document;
use App\Models\DocumentHistory;
use App\Models\Office;
use App\Models\User;
use App\Support\RoleNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserDeactivationApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $targetUser;
    protected Office $office;

    protected function setUp(): void
    {
        parent::setUp();

        if (! DB::table('roles')->where('name', RoleNames::ADMIN)->exists()) {
            DB::table('roles')->insert([
                'id' => (string) Str::uuid(),
                'name' => RoleNames::ADMIN,
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
            ['name' => 'users.manage', 'guard_name' => 'web'],
            ['id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]
        );

        $adminRoleId = DB::table('roles')->where('name', RoleNames::ADMIN)->value('id');
        $permissionId = DB::table('permissions')->where('name', 'users.manage')->value('id');

        DB::table('role_has_permissions')->updateOrInsert([
            'permission_id' => $permissionId,
            'role_id' => $adminRoleId,
        ]);

        $this->office = Office::create([
            'id' => (string) Str::uuid(),
            'name' => 'Administrative Office',
            'code' => 'ADMIN',
        ]);

        $this->adminUser = User::create([
            'id' => (string) Str::uuid(),
            'username' => 'admin_user',
            'password' => bcrypt('password123'),
            'first_name' => 'Admin',
            'last_name' => 'Manager',
            'office_id' => $this->office->id,
            'is_active' => true,
        ]);
        DB::table('model_has_roles')->insert([
            'role_id' => $adminRoleId,
            'model_type' => User::class,
            'model_id' => $this->adminUser->id,
        ]);

        $this->targetUser = User::create([
            'id' => (string) Str::uuid(),
            'username' => 'mahaly_ababa',
            'password' => bcrypt('password123'),
            'first_name' => 'Mahaly',
            'last_name' => 'Ababa',
            'employee_id' => '231032',
            'office_id' => $this->office->id,
            'birthdate' => '1995-05-15',
            'is_active' => true,
        ]);
        DB::table('model_has_roles')->insert([
            'role_id' => DB::table('roles')->where('name', 'employee')->where('guard_name', 'web')->value('id'),
            'model_type' => User::class,
            'model_id' => $this->targetUser->id,
        ]);
    }

    public function test_user_row_exists_before_deactivation(): void
    {
        $this->assertDatabaseHas('users', [
            'id' => $this->targetUser->id,
            'is_active' => true,
            'deleted_at' => null,
        ]);
    }

    public function test_authorized_admin_can_deactivate_user(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->patchJson("/api/users/{$this->targetUser->id}/deactivate");

        $response->assertStatus(200)
            ->assertJson(['message' => 'User account access has been removed.']);
    }

    public function test_deactivation_retains_same_user_row(): void
    {
        Sanctum::actingAs($this->adminUser);

        $this->patchJson("/api/users/{$this->targetUser->id}/deactivate")->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $this->targetUser->id,
        ]);
    }

    public function test_deactivation_sets_is_active_false(): void
    {
        Sanctum::actingAs($this->adminUser);

        $this->patchJson("/api/users/{$this->targetUser->id}/deactivate")->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $this->targetUser->id,
            'is_active' => false,
        ]);
    }

    public function test_deactivation_sets_deleted_at_timestamp(): void
    {
        Sanctum::actingAs($this->adminUser);

        $this->patchJson("/api/users/{$this->targetUser->id}/deactivate")->assertOk();

        $this->assertSoftDeleted('users', [
            'id' => $this->targetUser->id,
        ]);
    }

    public function test_deactivation_logs_account_deactivated_history_with_actor(): void
    {
        Sanctum::actingAs($this->adminUser);

        $this->patchJson("/api/users/{$this->targetUser->id}/deactivate")->assertOk();

        $this->assertDatabaseHas('account_history_events', [
            'subject_user_id' => $this->targetUser->id,
            'actor_user_id' => $this->adminUser->id,
            'event_type' => 'account.deactivated',
        ]);
    }

    public function test_deactivation_logs_account_deactivated_activity(): void
    {
        Sanctum::actingAs($this->adminUser);

        $this->patchJson("/api/users/{$this->targetUser->id}/deactivate")->assertOk();

        $this->assertDatabaseHas('activity_events', [
            'actor_user_id' => $this->adminUser->id,
            'activity_type' => 'account.deactivated',
            'module' => 'User Management',
        ]);
    }

    public function test_deactivated_user_cannot_authenticate(): void
    {
        Sanctum::actingAs($this->adminUser);
        $this->patchJson("/api/users/{$this->targetUser->id}/deactivate")->assertOk();

        $loginResponse = $this->postJson('/api/login', [
            'username' => $this->targetUser->username,
            'password' => 'password123',
        ]);

        $this->assertTrue(in_array($loginResponse->status(), [401, 403]));
    }

    public function test_deactivated_user_tokens_are_revoked(): void
    {
        Sanctum::actingAs($this->adminUser);
        $this->targetUser->createToken('test-token');
        $this->assertCount(1, $this->targetUser->tokens);

        $this->patchJson("/api/users/{$this->targetUser->id}/deactivate")->assertOk();

        $this->assertCount(0, $this->targetUser->fresh()->tokens);
    }

    public function test_related_documents_remain_in_database(): void
    {
        $document = Document::create([
            'id' => (string) Str::uuid(),
            'tracking_number' => 'DOC-TEST-001',
            'title' => 'Test Employee Document',
            'document_type' => 'Memorandum',
            'sender_name' => 'Admin Office',
            'origin_sender' => 'Admin Office',
            'priority_level' => 'Normal',
            'description' => 'Test document description',
            'created_by' => $this->targetUser->id,
            'office_id' => $this->office->id,
            'status' => 'Pending',
        ]);

        Sanctum::actingAs($this->adminUser);
        $this->patchJson("/api/users/{$this->targetUser->id}/deactivate")->assertOk();

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'created_by' => $this->targetUser->id,
        ]);
    }

    public function test_related_document_count_does_not_decrease(): void
    {
        Document::create([
            'id' => (string) Str::uuid(),
            'tracking_number' => 'DOC-TEST-002',
            'title' => 'Doc 2',
            'document_type' => 'Letter',
            'sender_name' => 'Admin Office',
            'origin_sender' => 'Admin Office',
            'priority_level' => 'Normal',
            'description' => 'Test document description',
            'created_by' => $this->targetUser->id,
            'office_id' => $this->office->id,
            'status' => 'Pending',
        ]);

        $initialDocCount = DB::table('documents')->count();

        Sanctum::actingAs($this->adminUser);
        $this->patchJson("/api/users/{$this->targetUser->id}/deactivate")->assertOk();

        $this->assertEquals($initialDocCount, DB::table('documents')->count());
    }

    public function test_related_activity_logs_remain_in_database(): void
    {
        $activity = ActivityEvent::create([
            'id' => (string) Str::uuid(),
            'actor_user_id' => $this->targetUser->id,
            'activity_type' => 'auth.login_succeeded',
            'module' => 'Authentication',
            'description' => 'User logged in',
        ]);

        Sanctum::actingAs($this->adminUser);
        $this->patchJson("/api/users/{$this->targetUser->id}/deactivate")->assertOk();

        $this->assertDatabaseHas('activity_events', [
            'id' => $activity->id,
            'actor_user_id' => $this->targetUser->id,
        ]);
    }

    public function test_related_account_history_events_remain_in_database(): void
    {
        $history = AccountHistoryEvent::create([
            'id' => (string) Str::uuid(),
            'subject_user_id' => $this->targetUser->id,
            'actor_user_id' => $this->adminUser->id,
            'event_type' => 'account.created',
            'summary' => 'Account created',
            'description' => 'Account created',
        ]);

        Sanctum::actingAs($this->adminUser);
        $this->patchJson("/api/users/{$this->targetUser->id}/deactivate")->assertOk();

        $this->assertDatabaseHas('account_history_events', [
            'id' => $history->id,
            'subject_user_id' => $this->targetUser->id,
        ]);
    }

    public function test_audit_references_remain_valid(): void
    {
        $document = Document::create([
            'id' => (string) Str::uuid(),
            'tracking_number' => 'DOC-TEST-003',
            'title' => 'Doc 3',
            'document_type' => 'Letter',
            'sender_name' => 'Admin Office',
            'origin_sender' => 'Admin Office',
            'priority_level' => 'Normal',
            'description' => 'Test document description',
            'created_by' => $this->targetUser->id,
            'office_id' => $this->office->id,
            'status' => 'Pending',
        ]);

        DocumentHistory::create([
            'id' => (string) Str::uuid(),
            'document_id' => $document->id,
            'performed_by' => $this->targetUser->id,
            'action' => 'created',
            'details' => 'Document created by user',
        ]);

        Sanctum::actingAs($this->adminUser);
        $this->patchJson("/api/users/{$this->targetUser->id}/deactivate")->assertOk();

        $this->assertDatabaseHas('document_histories', [
            'document_id' => $document->id,
            'performed_by' => $this->targetUser->id,
        ]);
    }

    public function test_deactivating_already_deactivated_account_returns_422(): void
    {
        Sanctum::actingAs($this->adminUser);
        $this->patchJson("/api/users/{$this->targetUser->id}/deactivate")->assertOk();

        $secondCall = $this->patchJson("/api/users/{$this->targetUser->id}/deactivate");

        $secondCall->assertStatus(422)
            ->assertJson(['message' => 'This account is already deactivated.']);
    }

    public function test_deactivation_does_not_change_user_primary_key(): void
    {
        $originalId = $this->targetUser->id;

        Sanctum::actingAs($this->adminUser);
        $this->patchJson("/api/users/{$originalId}/deactivate")->assertOk();

        $userInDb = DB::table('users')->where('id', $originalId)->first();
        $this->assertNotNull($userInDb);
        $this->assertEquals($originalId, $userInDb->id);
    }

    public function test_cannot_deactivate_self(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->patchJson("/api/users/{$this->adminUser->id}/deactivate");

        $response->assertStatus(422)
            ->assertJson(['message' => 'You cannot deactivate your own account.']);
    }

    public function test_unauthorized_user_cannot_deactivate(): void
    {
        $employeeRole = DB::table('roles')->where('name', 'employee')->first();
        $unauthorizedUser = User::create([
            'id' => (string) Str::uuid(),
            'username' => 'regular_user',
            'password' => bcrypt('password123'),
            'first_name' => 'Regular',
            'last_name' => 'User',
            'office_id' => $this->office->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($unauthorizedUser);

        $response = $this->patchJson("/api/users/{$this->targetUser->id}/deactivate");

        $response->assertStatus(403);
    }

    public function test_legacy_delete_user_route_is_not_found(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->deleteJson("/api/users/{$this->targetUser->id}");

        $response->assertStatus(405);
    }

    public function test_deactivated_users_are_excluded_from_active_user_listing(): void
    {
        Sanctum::actingAs($this->adminUser);
        $this->patchJson("/api/users/{$this->targetUser->id}/deactivate")->assertOk();

        // Active status query excludes deactivated users
        $response = $this->getJson('/api/users?status=active');
        $response->assertOk();
        $userIds = collect($response->json('data'))->pluck('id');
        $this->assertNotContains($this->targetUser->id, $userIds);

        // All status query includes deactivated users
        $allResponse = $this->getJson('/api/users?status=all');
        $allResponse->assertOk();
        $allUserIds = collect($allResponse->json('data'))->pluck('id');
        $this->assertContains($this->targetUser->id, $allUserIds);
    }

    public function test_deactivated_users_can_be_retrieved_through_audit_queries(): void
    {
        Sanctum::actingAs($this->adminUser);
        $this->patchJson("/api/users/{$this->targetUser->id}/deactivate")->assertOk();

        $response = $this->getJson("/api/users/{$this->targetUser->id}/security");

        $response->assertOk()
            ->assertJsonPath('username', $this->targetUser->username);
    }

    public function test_force_delete_is_never_invoked_by_deactivation(): void
    {
        Sanctum::actingAs($this->adminUser);
        $this->patchJson("/api/users/{$this->targetUser->id}/deactivate")->assertOk();

        // Check SQL row directly in database
        $userRow = DB::table('users')->where('id', $this->targetUser->id)->first();
        $this->assertNotNull($userRow);
        $this->assertNotNull($userRow->deleted_at);
        $this->assertEquals(0, $userRow->is_active);
    }

    public function test_response_message_confirms_access_removed(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->patchJson("/api/users/{$this->targetUser->id}/deactivate");

        $response->assertOk()
            ->assertJsonPath('message', 'User account access has been removed.');
    }
}
