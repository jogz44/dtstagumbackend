<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserSecurityAccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $regularUser;
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
            'password' => Hash::make('AdminPass123!'),
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'office_id' => $this->office->id,
            'birthdate' => '1990-01-01',
            'is_active' => true,
        ]);
        $this->adminUser->assignRole('admin');

        $this->regularUser = User::create([
            'username' => 'reguser',
            'password' => Hash::make('UserPass123!'),
            'first_name' => 'Regular',
            'last_name' => 'User',
            'office_id' => $this->office->id,
            'birthdate' => '1992-05-15',
            'is_active' => true,
        ]);
        $this->regularUser->assignRole('employee');

        $this->targetUser = User::create([
            'username' => 'targetuser',
            'password' => Hash::make('TargetOriginalSecret123!'),
            'first_name' => 'Target',
            'last_name' => 'User',
            'office_id' => $this->office->id,
            'birthdate' => '1995-08-10',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $this->targetUser->assignRole('employee');
    }

    public function test_user_can_change_own_password_via_me_endpoint(): void
    {
        Sanctum::actingAs($this->regularUser);

        $response = $this->postJson('/api/me/change-password', [
            'current_password' => 'UserPass123!',
            'new_password' => 'NewUserPass456!',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Password updated successfully.',
                'must_change_password' => false,
            ]);

        $this->assertTrue(Hash::check('NewUserPass456!', $this->regularUser->fresh()->password));

        $this->assertDatabaseHas('account_history_events', [
            'subject_user_id' => $this->regularUser->id,
            'actor_user_id' => $this->regularUser->id,
            'event_type' => 'password.changed',
        ]);
    }

    public function test_user_cannot_change_password_with_incorrect_current_password(): void
    {
        Sanctum::actingAs($this->regularUser);

        $response = $this->postJson('/api/me/change-password', [
            'current_password' => 'WrongCurrentPassword!',
            'new_password' => 'NewUserPass456!',
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Current password is incorrect.']);

        $this->assertTrue(Hash::check('UserPass123!', $this->regularUser->fresh()->password));
    }

    public function test_admin_can_change_own_password_via_me_endpoint(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->postJson('/api/me/change-password', [
            'current_password' => 'AdminPass123!',
            'new_password' => 'NewAdminPass999!',
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Password updated successfully.']);

        $this->assertTrue(Hash::check('NewAdminPass999!', $this->adminUser->fresh()->password));
    }

    public function test_admin_cannot_directly_set_target_user_password_via_user_update_api(): void
    {
        Sanctum::actingAs($this->adminUser);

        $originalPasswordHash = $this->targetUser->password;

        // Admin attempts to bypass access control by injecting password into user update payload
        $response = $this->putJson("/api/users/{$this->targetUser->id}", [
            'username' => 'targetuser_updated',
            'password' => 'HackedDirectPassword123!',
        ]);

        // Must fail with validation error because password is a prohibited field in user update requests
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        // Assert target user's password in database is strictly unchanged
        $freshTarget = $this->targetUser->fresh();
        $this->assertEquals($originalPasswordHash, $freshTarget->password);
        $this->assertFalse(Hash::check('HackedDirectPassword123!', $freshTarget->password));
        $this->assertTrue(Hash::check('TargetOriginalSecret123!', $freshTarget->password));
    }

    public function test_admin_can_reset_another_users_password_via_reset_password_endpoint(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->postJson("/api/users/{$this->targetUser->id}/reset-password");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Password reset successfully.',
                'temporary_password' => '08101995',
                'must_change_password' => true,
            ]);

        $freshTarget = $this->targetUser->fresh();
        $this->assertTrue(Hash::check('08101995', $freshTarget->password));
        $this->assertTrue($freshTarget->must_change_password);

        $this->assertDatabaseHas('account_history_events', [
            'subject_user_id' => $this->targetUser->id,
            'actor_user_id' => $this->adminUser->id,
            'event_type' => 'password.reset',
        ]);
    }

    public function test_normal_user_cannot_reset_another_users_password(): void
    {
        Sanctum::actingAs($this->regularUser);

        $response = $this->postJson("/api/users/{$this->targetUser->id}/reset-password");

        $response->assertStatus(403);
        $this->assertTrue(Hash::check('TargetOriginalSecret123!', $this->targetUser->fresh()->password));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response1 = $this->postJson('/api/me/change-password', [
            'current_password' => 'UserPass123!',
            'new_password' => 'NewUserPass456!',
        ]);
        $response1->assertStatus(401);

        $response2 = $this->postJson("/api/users/{$this->targetUser->id}/reset-password");
        $response2->assertStatus(401);
    }

    public function test_password_audit_logging_does_not_expose_passwords(): void
    {
        Sanctum::actingAs($this->adminUser);
        $this->postJson("/api/users/{$this->targetUser->id}/reset-password");

        Sanctum::actingAs($this->regularUser);
        $this->postJson('/api/me/change-password', [
            'current_password' => 'UserPass123!',
            'new_password' => 'NewUserPass456!',
        ]);

        $auditEvents = DB::table('account_history_events')
            ->whereIn('event_type', ['password.reset', 'password.changed'])
            ->get();

        $this->assertGreaterThanOrEqual(2, $auditEvents->count());

        foreach ($auditEvents as $event) {
            $json = json_encode($event);
            $this->assertStringNotContainsString('UserPass123!', $json);
            $this->assertStringNotContainsString('NewUserPass456!', $json);
            $this->assertStringNotContainsString('TargetOriginalSecret123!', $json);
        }
    }
}
