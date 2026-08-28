<?php

namespace Tests\Feature;

use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserListApiTest extends TestCase
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

        if (! DB::table('roles')->where('name', 'receiver')->exists()) {
            DB::table('roles')->insert([
                'id' => (string) Str::uuid(),
                'name' => 'receiver',
                'display_name' => 'Receiver',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function createUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'username' => 'testuser_' . Str::random(5),
            'password' => bcrypt('password'),
            'first_name' => 'Test',
            'last_name' => 'User',
            'employee_id' => 'EMP-' . rand(1000, 9999),
            'birthdate' => '1995-01-01',
            'office_id' => 'ocm',
            'position' => 'Staff',
            'is_active' => true,
        ], $overrides));
    }

    public function test_api_users_returns_paginated_users_and_defaults_to_25(): void
    {
        $admin = $this->createUser();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->getJson('/api/users');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'username',
                        'first_name',
                        'last_name',
                        'full_name',
                        'employee_id',
                        'position',
                        'employment_status',
                        'is_active',
                        'office',
                        'role',
                    ],
                ],
                'links',
                'meta' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                ],
            ]);

        $this->assertEquals(25, $response->json('meta.per_page'));
    }

    public function test_api_users_invalid_or_oversized_per_page_falls_back_to_25(): void
    {
        $admin = $this->createUser();
        $admin->assignRole('admin');

        // Oversized per_page
        $response = $this->actingAs($admin)->getJson('/api/users?per_page=100000');
        $response->assertStatus(200);
        $this->assertEquals(25, $response->json('meta.per_page'));

        // Invalid arbitrary per_page
        $response = $this->actingAs($admin)->getJson('/api/users?per_page=17');
        $response->assertStatus(200);
        $this->assertEquals(25, $response->json('meta.per_page'));

        // Allowed page size 50
        $response = $this->actingAs($admin)->getJson('/api/users?per_page=50');
        $response->assertStatus(200);
        $this->assertEquals(50, $response->json('meta.per_page'));

        // Allowed page size 100
        $response = $this->actingAs($admin)->getJson('/api/users?per_page=100');
        $response->assertStatus(200);
        $this->assertEquals(100, $response->json('meta.per_page'));
    }

    public function test_api_users_search_by_name_employee_id_username_and_email(): void
    {
        $admin = $this->createUser([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'employee_id' => 'EMP-8888',
            'username' => 'juandc',
            'email' => 'juan@hrmo.gov.ph',
        ]);
        $admin->assignRole('admin');

        $this->createUser([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'employee_id' => 'EMP-9999',
            'username' => 'mariaserver',
        ]);

        // Search by first name
        $response = $this->actingAs($admin)->getJson('/api/users?search=Juan');
        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertEquals($admin->id, $response->json('data.0.id'));

        // Search by employee ID
        $response = $this->actingAs($admin)->getJson('/api/users?search=EMP-8888');
        $response->assertStatus(200)->assertJsonCount(1, 'data');

        // Search by username
        $response = $this->actingAs($admin)->getJson('/api/users?search=juandc');
        $response->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_api_users_filters_by_role_and_active_status(): void
    {
        $admin = $this->createUser(['is_active' => true]);
        $admin->assignRole('admin');

        $receiver = $this->createUser(['is_active' => false]);
        $receiver->assignRole('receiver');

        // Filter by role
        $response = $this->actingAs($admin)->getJson('/api/users?role=receiver');
        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertEquals($receiver->id, $response->json('data.0.id'));

        // Filter by active status
        $response = $this->actingAs($admin)->getJson('/api/users?is_active=false');
        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertEquals($receiver->id, $response->json('data.0.id'));
    }

    public function test_unauthenticated_user_cannot_access_api_users(): void
    {
        $response = $this->getJson('/api/users');
        $response->assertStatus(401);
    }
}
