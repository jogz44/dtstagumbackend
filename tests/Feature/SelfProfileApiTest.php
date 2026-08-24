<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SelfProfileApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Office::create(['id' => 'ocm', 'name' => 'Office of the City Mayor', 'code' => 'OCM']);

        if (! DB::table('roles')->where('name', 'admin')->exists()) {
            DB::table('roles')->insert([
                'id' => (string) Str::uuid(),
                'name' => 'admin',
                'display_name' => 'Admin',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function createUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'username' => 'selfuser_' . Str::random(5),
            'password' => bcrypt('password'),
            'first_name' => 'Self',
            'last_name' => 'User',
            'email' => 'self_' . Str::random(5) . '@example.com',
            'employee_id' => 'EMP-' . rand(1000, 9999),
            'birthdate' => '1995-01-01',
            'office_id' => 'ocm',
            'position' => 'Staff',
            'is_active' => true,
        ], $overrides));
    }

    public function test_get_me_returns_authenticated_user_profile(): void
    {
        $user = $this->createUser(['first_name' => 'Jane', 'last_name' => 'Doe']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/me');

        $response->assertStatus(200);

        $payload = $response->json('data') ?? $response->json();
        $this->assertEquals($user->id, $payload['id']);
        $this->assertEquals('Jane Doe', $payload['full_name']);
        $this->assertEquals($user->employee_id, $payload['employee_id']);
        $this->assertEquals($user->email, $payload['email']);
    }

    public function test_patch_me_updates_self_permitted_fields(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user, 'sanctum')
            ->patchJson('/api/me', [
                'first_name' => 'UpdatedFirst',
                'last_name' => 'UpdatedLast',
                'email' => 'updated_email@example.com',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('first_name', 'UpdatedFirst')
            ->assertJsonPath('email', 'updated_email@example.com');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'first_name' => 'UpdatedFirst',
            'email' => 'updated_email@example.com',
        ]);
    }

    public function test_self_endpoints_derive_identity_from_authenticated_user(): void
    {
        $user1 = $this->createUser(['first_name' => 'User1']);

        // Authenticated as User1, requesting /api/me/security
        $response = $this->actingAs($user1, 'sanctum')
            ->getJson('/api/me/security');

        $response->assertStatus(200)
            ->assertJsonPath('username', $user1->username);

        // Authenticated as User1, requesting /api/me/history
        $response = $this->actingAs($user1, 'sanctum')
            ->getJson('/api/me/history');

        $response->assertStatus(200);

        // Authenticated as User1, requesting /api/me/activity
        $response = $this->actingAs($user1, 'sanctum')
            ->getJson('/api/me/activity');

        $response->assertStatus(200);
    }
}
