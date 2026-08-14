<?php

namespace Tests\Feature;

use App\Models\AccountHistoryEvent;
use App\Models\ActivityEvent;
use App\Models\Document;
use App\Models\DocumentHistory;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserDeleteApiTest extends TestCase
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
            'username' => 'targetuser',
            'password' => bcrypt('password'),
            'first_name' => 'Target',
            'last_name' => 'User',
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

    public function test_authorized_admin_can_delete_user(): void
    {
        Sanctum::actingAs($this->adminUser);

        $this->targetUser->createToken('test-token');
        $this->assertCount(1, $this->targetUser->tokens);

        $response = $this->deleteJson("/api/users/{$this->targetUser->id}");

        $response->assertStatus(200)
            ->assertJson(['message' => 'User account deleted successfully.']);

        $this->assertSoftDeleted('users', ['id' => $this->targetUser->id]);
        $this->assertDatabaseHas('users', [
            'id' => $this->targetUser->id,
            'is_active' => false,
        ]);

        $this->assertCount(0, $this->targetUser->fresh()->tokens);

        $this->assertDatabaseHas('account_history_events', [
            'subject_user_id' => $this->targetUser->id,
            'actor_user_id' => $this->adminUser->id,
            'event_type' => 'account.deleted',
        ]);

        $this->assertDatabaseHas('activity_events', [
            'actor_user_id' => $this->adminUser->id,
            'activity_type' => 'account.deleted',
        ]);
    }

    public function test_unauthorized_user_cannot_delete_user(): void
    {
        $regularUser = User::create([
            'username' => 'regularuser',
            'password' => bcrypt('password'),
            'first_name' => 'Regular',
            'last_name' => 'User',
            'office_id' => $this->office->id,
            'birthdate' => '1992-03-10',
            'is_active' => true,
        ]);
        DB::table('model_has_roles')->insert([
            'role_id' => DB::table('roles')->where('name', 'employee')->where('guard_name', 'web')->value('id'),
            'model_type' => User::class,
            'model_id' => $regularUser->id,
        ]);

        Sanctum::actingAs($regularUser);

        $response = $this->deleteJson("/api/users/{$this->targetUser->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', [
            'id' => $this->targetUser->id,
            'deleted_at' => null,
        ]);
    }

    public function test_cannot_self_delete_account(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->deleteJson("/api/users/{$this->adminUser->id}");

        $response->assertStatus(422)
            ->assertJson(['message' => 'You cannot delete your own account.']);

        $this->assertDatabaseHas('users', [
            'id' => $this->adminUser->id,
            'deleted_at' => null,
        ]);
    }

    public function test_deleted_user_cannot_authenticate(): void
    {
        $this->targetUser->delete();

        $response = $this->postJson('/api/login', [
            'username' => 'targetuser',
            'password' => 'password',
        ]);

        $response->assertStatus(401);
    }

    public function test_historical_document_references_remain_valid(): void
    {
        Sanctum::actingAs($this->adminUser);

        $document = Document::create([
            'tracking_number' => 'DOC-2026-0001',
            'created_by' => $this->targetUser->id,
            'origin_sender' => 'Target Sender',
            'sender_name' => 'Target Sender Name',
            'document_type' => 'Memorandum',
            'priority_level' => 'Routine',
            'description' => 'Test document description',
            'status' => 'Pending',
        ]);

        DocumentHistory::record($document, DocumentHistory::REGISTERED, $this->targetUser);

        $response = $this->deleteJson("/api/users/{$this->targetUser->id}");
        $response->assertStatus(200);

        $this->assertSoftDeleted('users', ['id' => $this->targetUser->id]);

        $fetchedDoc = Document::with('creator')->find($document->id);
        $this->assertNotNull($fetchedDoc);
        $this->assertNotNull($fetchedDoc->creator);
        $this->assertEquals($this->targetUser->id, $fetchedDoc->creator->id);

        $history = DocumentHistory::with('user')->where('document_id', $document->id)->first();
        $this->assertNotNull($history);
        $this->assertNotNull($history->user);
        $this->assertEquals($this->targetUser->id, $history->user->id);
    }
}
