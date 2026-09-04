<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserPasswordResetApiTest extends TestCase
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
            'password' => bcrypt('oldpassword'),
            'first_name' => 'Target',
            'last_name' => 'User',
            'office_id' => $this->office->id,
            'birthdate' => '1995-08-10',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $this->targetUser->assignRole('employee');
    }

    public function test_admin_can_reset_user_password_to_birthdate(): void
    {
        Sanctum::actingAs($this->adminUser);

        $this->targetUser->createToken('active-session');
        $this->assertCount(1, $this->targetUser->tokens);

        $response = $this->postJson("/api/users/{$this->targetUser->id}/password-reset");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Password reset successfully.',
                'temporary_password' => '08101995',
                'must_change_password' => true,
            ]);

        $freshUser = $this->targetUser->fresh();
        $this::assertTrue(Hash::check('08101995', $freshUser->password));
        $this::assertTrue($freshUser->must_change_password);

        $this->assertCount(0, $freshUser->tokens);

        $this->assertDatabaseHas('account_history_events', [
            'subject_user_id' => $this->targetUser->id,
            'actor_user_id' => $this->adminUser->id,
            'event_type' => 'password.reset',
        ]);

        $this->assertDatabaseHas('activity_events', [
            'actor_user_id' => $this->adminUser->id,
            'activity_type' => 'password.reset',
        ]);
    }

    public function test_unauthorized_user_cannot_reset_password(): void
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

        $response = $this->postJson("/api/users/{$this->targetUser->id}/password-reset");
        $response->assertStatus(403);
    }

    public function test_cannot_reset_password_for_deleted_user(): void
    {
        Sanctum::actingAs($this->adminUser);

        $this->targetUser->delete();

        $response = $this->postJson("/api/users/{$this->targetUser->id}/password-reset");

        $response->assertStatus(422)
            ->assertJson(['message' => 'Cannot reset the password of a deleted account.']);
    }

    public function test_cannot_reset_password_when_birthdate_missing(): void
    {
        Sanctum::actingAs($this->adminUser);

        $noBirthdateUser = User::create([
            'username' => 'nobirthdate',
            'password' => bcrypt('password'),
            'first_name' => 'No',
            'last_name' => 'Birthdate',
            'office_id' => $this->office->id,
            'birthdate' => null,
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/users/{$noBirthdateUser->id}/password-reset");

        $response->assertStatus(422)
            ->assertJson(['message' => 'Cannot reset password because this user has no birthdate on file.']);
    }

    public function test_user_can_login_with_temporary_password_and_must_change_password(): void
    {
        Sanctum::actingAs($this->adminUser);
        $this->postJson("/api/users/{$this->targetUser->id}/password-reset");

        $loginResponse = $this->postJson('/api/login', [
            'username' => 'targetuser',
            'password' => '08101995',
        ]);

        $loginResponse->assertStatus(200)
            ->assertJson([
                'must_change_password' => true,
            ]);

        $freshTargetUser = $this->targetUser->fresh();
        Sanctum::actingAs($freshTargetUser);

        $changeResponse = $this->postJson('/api/change-password', [
            'current_password' => '08101995',
            'new_password' => 'NewSecret123!',
        ]);

        $changeResponse->assertStatus(200)
            ->assertJson([
                'must_change_password' => false,
            ]);

        $this::assertFalse($freshTargetUser->fresh()->must_change_password);
        $this::assertTrue(Hash::check('NewSecret123!', $freshTargetUser->fresh()->password));
    }
}
