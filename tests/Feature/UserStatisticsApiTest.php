<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserStatisticsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'username' => 'admin',
            'password' => bcrypt('password123'),
            'first_name' => 'Admin',
            'last_name' => 'Stats',
            'is_active' => true,
            'must_change_password' => false,
        ]);

        Sanctum::actingAs($this->admin);
    }

    private function createUser(array $overrides = []): User
    {
        static $counter = 1;
        $counter++;

        return User::create(array_merge([
            'username' => 'testuser_'.$counter.'_'.Str::random(5),
            'password' => bcrypt('password123'),
            'first_name' => 'Test',
            'last_name' => 'User '.$counter,
            'is_active' => true,
            'must_change_password' => false,
        ], $overrides));
    }

    public function test_user_statistics_returns_correct_mutually_exclusive_counts(): void
    {
        // $this->admin is active (1 active)
        $this->createUser(['is_active' => true]);
        $this->createUser(['is_active' => true]); // Total active = 3

        $this->createUser(['is_active' => false]);
        $this->createUser(['is_active' => false]);
        $this->createUser(['is_active' => false]); // Total normal inactive = 3

        $deactivatedUser = $this->createUser(['is_active' => false]);
        $deactivatedUser->delete(); // Total deactivated = 1

        $response = $this->getJson('/api/users/statistics');

        $response->assertStatus(200)
            ->assertJson([
                'total_users' => 7, // 3 active + 3 inactive + 1 deactivated = 7
                'active_users' => 3,
                'inactive_users' => 4, // 3 normal inactive + 1 deactivated = 4
                'breakdown' => [
                    'active' => 3,
                    'inactive' => 3,
                    'deactivated' => 1,
                ],
            ]);
    }

    public function test_deactivated_user_appears_exactly_once_in_total_users(): void
    {
        $user = $this->createUser(['is_active' => true]);

        $initialResponse = $this->getJson('/api/users/statistics');
        $initialTotal = $initialResponse->json('total_users');

        // Soft delete the user
        $user->delete();

        $afterDeactivateResponse = $this->getJson('/api/users/statistics');

        $this->assertEquals($initialTotal, $afterDeactivateResponse->json('total_users'));
        $this->assertEquals(1, $afterDeactivateResponse->json('breakdown.deactivated'));
    }

    public function test_status_filtering_is_mutually_exclusive(): void
    {
        $activeUser = $this->createUser(['is_active' => true, 'username' => 'active_test']);
        $inactiveUser = $this->createUser(['is_active' => false, 'username' => 'inactive_test']);
        $deactivatedUser = $this->createUser(['is_active' => false, 'username' => 'deactivated_test']);
        $deactivatedUser->delete();

        // 1. Status=active
        $activeResponse = $this->getJson('/api/users?status=active');
        $activeResponse->assertStatus(200);
        $activeUsernames = collect($activeResponse->json('data'))->pluck('username');
        $this->assertContains('active_test', $activeUsernames);
        $this->assertNotContains('inactive_test', $activeUsernames);
        $this->assertNotContains('deactivated_test', $activeUsernames);

        // 2. Status=inactive (normal inactive only, excluding soft-deleted)
        $inactiveResponse = $this->getJson('/api/users?status=inactive');
        $inactiveResponse->assertStatus(200);
        $inactiveUsernames = collect($inactiveResponse->json('data'))->pluck('username');
        $this->assertNotContains('active_test', $inactiveUsernames);
        $this->assertContains('inactive_test', $inactiveUsernames);
        $this->assertNotContains('deactivated_test', $inactiveUsernames);

        // 3. Status=deactivated (soft-deleted only)
        $deactivatedResponse = $this->getJson('/api/users?status=deactivated');
        $deactivatedResponse->assertStatus(200);
        $deactivatedUsernames = collect($deactivatedResponse->json('data'))->pluck('username');
        $this->assertNotContains('active_test', $deactivatedUsernames);
        $this->assertNotContains('inactive_test', $deactivatedUsernames);
        $this->assertContains('deactivated_test', $deactivatedUsernames);

        // 4. Status=all (includes active, inactive, and soft-deleted)
        $allResponse = $this->getJson('/api/users?status=all');
        $allResponse->assertStatus(200);
        $allUsernames = collect($allResponse->json('data'))->pluck('username');
        $this->assertContains('active_test', $allUsernames);
        $this->assertContains('inactive_test', $allUsernames);
        $this->assertContains('deactivated_test', $allUsernames);
    }

    public function test_user_resource_includes_account_status(): void
    {
        $user = $this->createUser(['is_active' => true]);

        $response = $this->getJson("/api/users/{$user->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.account_status', 'active');
    }
}
