<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_fetch_reports(): void
    {
        $user = User::create([
            'username' => 'testadmin',
            'password' => bcrypt('password'),
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'must_change_password' => false,
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/reports');

        $response->assertOk()
            ->assertJsonStructure([
                'kpiCards',
                'breakdownPanels',
                'divisionBlocks',
                'documents',
            ]);
    }

    public function test_unauthenticated_user_cannot_access_reports(): void
    {
        $response = $this->getJson('/api/reports');

        $response->assertStatus(401);
    }
}
