<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserActiveSessionsApiTest extends TestCase
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
        $this->adminUser->assignRole('admin');

        $this->targetUser = User::create([
            'username' => 'targetuser',
            'password' => bcrypt('password'),
            'first_name' => 'Target',
            'last_name' => 'User',
            'office_id' => $this->office->id,
            'birthdate' => '1995-05-15',
            'is_active' => true,
        ]);
        $this->targetUser->assignRole('employee');
    }

    public function test_authorized_user_can_read_sessions_with_current_session_flag(): void
    {
        $token1 = $this->targetUser->createToken('session-1');
        $token2 = $this->targetUser->createToken('session-2');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token2->plainTextToken,
        ])->getJson("/api/users/{$this->targetUser->id}/security");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'sessions' => [
                    '*' => ['id', 'name', 'device', 'ip_address', 'location', 'last_activity_at', 'issued_at', 'expires_at', 'current'],
                ],
            ]);

        $sessions = $response->json('sessions');
        $this->assertCount(2, $sessions);

        $currentSession = collect($sessions)->firstWhere('current', true);
        $this->assertNotNull($currentSession);
        $this->assertEquals($token2->accessToken->id, $currentSession['id']);
    }

    public function test_unauthorized_user_cannot_read_or_revoke_sessions(): void
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
        $regularUser->assignRole('employee');

        Sanctum::actingAs($regularUser);

        $readResponse = $this->getJson("/api/users/{$this->targetUser->id}/security");
        $readResponse->assertStatus(403);

        $revokeResponse = $this->deleteJson("/api/users/{$this->targetUser->id}/sessions");
        $revokeResponse->assertStatus(403);
    }

    public function test_single_session_revoke_works_and_audits(): void
    {
        Sanctum::actingAs($this->adminUser);

        $token1 = $this->targetUser->createToken('session-1');
        $token2 = $this->targetUser->createToken('session-2');

        $response = $this->deleteJson("/api/users/{$this->targetUser->id}/sessions/{$token1->accessToken->id}");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Session revoked.']);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token1->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token2->accessToken->id]);

        $this->assertDatabaseHas('account_history_events', [
            'subject_user_id' => $this->targetUser->id,
            'actor_user_id' => $this->adminUser->id,
            'event_type' => 'session.revoked',
        ]);

        $this->assertDatabaseHas('activity_events', [
            'actor_user_id' => $this->adminUser->id,
            'activity_type' => 'session.revoked',
        ]);
    }

    public function test_cannot_revoke_another_users_token_through_target_endpoint(): void
    {
        Sanctum::actingAs($this->adminUser);

        $adminToken = $this->adminUser->createToken('admin-token');

        $response = $this->deleteJson("/api/users/{$this->targetUser->id}/sessions/{$adminToken->accessToken->id}");

        $response->assertStatus(404)
            ->assertJson(['message' => 'Session token not found for this user.']);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $adminToken->accessToken->id]);
    }

    public function test_revoke_other_sessions_behaves_correctly(): void
    {
        $token1 = $this->targetUser->createToken('session-1');
        $token2 = $this->targetUser->createToken('session-2');
        $token3 = $this->targetUser->createToken('session-3');

        // Self revokes OTHER sessions (token1 and token3 removed, token2 preserved)
        $selfResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.$token2->plainTextToken,
        ])->deleteJson("/api/users/{$this->targetUser->id}/sessions");

        $selfResponse->assertStatus(200);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token1->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token2->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token3->accessToken->id]);

        // Admin revokes ALL remaining target user sessions
        Sanctum::actingAs($this->adminUser);
        $adminResponse = $this->deleteJson("/api/users/{$this->targetUser->id}/sessions");

        $adminResponse->assertStatus(200);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token2->accessToken->id]);
    }

    public function test_cannot_revoke_sessions_for_deleted_user(): void
    {
        Sanctum::actingAs($this->adminUser);

        $token = $this->targetUser->createToken('session-1');
        $this->targetUser->delete();

        $singleRevoke = $this->deleteJson("/api/users/{$this->targetUser->id}/sessions/{$token->accessToken->id}");
        $singleRevoke->assertStatus(422)
            ->assertJson(['message' => 'Cannot revoke sessions for a deleted account.']);

        $allRevoke = $this->deleteJson("/api/users/{$this->targetUser->id}/sessions");
        $allRevoke->assertStatus(422)
            ->assertJson(['message' => 'Cannot revoke sessions for a deleted account.']);
    }
}
