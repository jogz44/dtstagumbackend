<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentHistory;
use App\Models\Office;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardAnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $userA;
    private User $userB;
    private User $supervisor;
    private Office $officeA;
    private Office $officeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->officeA = Office::create(['name' => "City Mayor's Office", 'code' => 'CMO']);
        $this->officeB = Office::create(['name' => "City Administrator's Office", 'code' => 'CAO']);

        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $userRole = Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
        $supervisorRole = Role::firstOrCreate(['name' => 'supervisor', 'guard_name' => 'web']);

        $this->admin = User::create([
            'username' => 'admin_user',
            'first_name' => 'System',
            'last_name' => 'Admin',
            'password' => Hash::make('password'),
            'office_id' => $this->officeA->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);
        $this->admin->assignRole($adminRole);

        $this->userA = User::create([
            'username' => 'user_a',
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'password' => Hash::make('password'),
            'office_id' => $this->officeA->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);
        $this->userA->assignRole($userRole);

        $this->userB = User::create([
            'username' => 'user_b',
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'password' => Hash::make('password'),
            'office_id' => $this->officeB->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);
        $this->userB->assignRole($userRole);

        $this->supervisor = User::create([
            'username' => 'supervisor_user',
            'first_name' => 'Office',
            'last_name' => 'Supervisor',
            'password' => Hash::make('password'),
            'office_id' => $this->officeA->id,
            'must_change_password' => false,
            'is_active' => true,
        ]);
        $this->supervisor->assignRole($supervisorRole);
    }

    private function createTestDoc(array $overrides = []): Document
    {
        return Document::create(array_merge([
            'tracking_number' => 'DOC-' . uniqid(),
            'origin_sender' => 'Origin Office',
            'sender_name' => 'Sender Person',
            'document_type' => 'Request',
            'priority_level' => 'Normal',
            'description' => 'Test Document Description',
            'status' => 'registered',
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    public function test_dashboard_total_matches_authorized_document_total_and_scoping()
    {
        $this->createTestDoc([
            'tracking_number' => 'DOC-001',
            'created_by' => $this->userB->id,
            'office_assignment' => $this->officeB->name,
        ]);

        $this->createTestDoc([
            'tracking_number' => 'DOC-002',
            'status' => 'in_process',
            'created_by' => $this->userA->id,
            'office_assignment' => $this->officeA->name,
        ]);

        $this->createTestDoc([
            'tracking_number' => 'DOC-003',
            'status' => 'completed',
            'created_by' => $this->userB->id,
            'office_assignment' => $this->officeB->name,
        ]);

        // Admin sees all 3
        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/dashboard/analytics?range=this_month');
        $response->assertOk()
            ->assertJsonPath('summary.total_documents', 3);

        // User A only sees Document 2 created by them
        Sanctum::actingAs($this->userA);
        $responseA = $this->getJson('/api/dashboard/analytics?range=this_month');
        $responseA->assertOk()
            ->assertJsonPath('summary.total_documents', 1);
    }

    /** @test */
    public function status_distribution_sums_to_expected_workflow_total()
    {
        $this->createTestDoc(['tracking_number' => 'DOC-101', 'status' => 'registered', 'created_by' => $this->admin->id]);
        $this->createTestDoc(['tracking_number' => 'DOC-102', 'status' => 'outgoing', 'created_by' => $this->admin->id]);
        $this->createTestDoc(['tracking_number' => 'DOC-103', 'status' => 'received', 'created_by' => $this->admin->id]);
        $this->createTestDoc(['tracking_number' => 'DOC-104', 'status' => 'in_process', 'created_by' => $this->admin->id]);
        $this->createTestDoc(['tracking_number' => 'DOC-105', 'status' => 'completed', 'created_by' => $this->admin->id]);

        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/dashboard/analytics?range=this_month');
        $response->assertOk();

        $dist = $response->json('status_distribution');
        $sum = array_sum($dist);

        $this->assertEquals(5, $sum);
        $this->assertEquals(1, $dist['registered']);
        $this->assertEquals(1, $dist['routed']);
        $this->assertEquals(1, $dist['received']);
        $this->assertEquals(1, $dist['in_process']);
        $this->assertEquals(1, $dist['completed']);
    }

    /** @test */
    public function incoming_and_outgoing_use_the_same_classification_as_tracking()
    {
        $this->createTestDoc([
            'tracking_number' => 'DOC-INC',
            'status' => 'incoming',
            'assigned_user' => $this->userA->full_name,
            'created_by' => $this->admin->id,
        ]);

        $this->createTestDoc([
            'tracking_number' => 'DOC-OUT',
            'status' => 'outgoing',
            'created_by' => $this->userA->id,
        ]);

        Sanctum::actingAs($this->userA);
        $response = $this->getJson('/api/dashboard/analytics?range=this_month');
        $response->assertOk()
            ->assertJsonPath('direction_distribution.incoming', 1)
            ->assertJsonPath('direction_distribution.outgoing', 1);
    }

    /** @test */
    public function overdue_count_matches_tracking_overdue_logic()
    {
        // Overdue document (due_date in past, non-terminal status)
        $this->createTestDoc([
            'tracking_number' => 'DOC-OVERDUE',
            'status' => 'in_process',
            'due_date' => Carbon::yesterday()->format('Y-m-d'),
            'created_by' => $this->admin->id,
        ]);

        // Completed document with past due_date (NOT overdue because terminal)
        $this->createTestDoc([
            'tracking_number' => 'DOC-DONE-PAST',
            'status' => 'completed',
            'due_date' => Carbon::yesterday()->format('Y-m-d'),
            'created_by' => $this->admin->id,
        ]);

        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/dashboard/analytics?range=this_month');
        $response->assertOk()
            ->assertJsonPath('summary.overdue', 1);
    }

    /** @test */
    public function receiver_does_not_see_unrelated_documents()
    {
        // Document created by User B (unrelated to User A)
        $this->createTestDoc([
            'tracking_number' => 'DOC-UNRELATED',
            'status' => 'registered',
            'created_by' => $this->userB->id,
        ]);

        Sanctum::actingAs($this->userA);
        $response = $this->getJson('/api/dashboard/analytics?range=this_month');
        $response->assertOk()
            ->assertJsonPath('summary.total_documents', 0);
    }

    /** @test */
    public function supervisor_only_receives_broader_scope_when_permission_allows_it()
    {
        // Document created by User A (in Office A)
        $this->createTestDoc([
            'tracking_number' => 'DOC-OFFICE-A',
            'status' => 'registered',
            'created_by' => $this->userA->id,
            'office_assignment' => $this->officeA->name,
        ]);

        // Document in Office B
        $this->createTestDoc([
            'tracking_number' => 'DOC-OFFICE-B',
            'status' => 'registered',
            'created_by' => $this->userB->id,
            'office_assignment' => $this->officeB->name,
        ]);

        Sanctum::actingAs($this->supervisor);
        $response = $this->getJson('/api/dashboard/analytics?range=this_month');
        $response->assertOk();

        // Supervisor only sees authorized documents (in Office A / involving supervisor)
        $total = $response->json('summary.total_documents');
        $this->assertLessThan(2, $total);
    }

    /** @test */
    public function custom_date_ranges_reject_invalid_date_from_after_date_to()
    {
        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/dashboard/analytics?range=custom&date_from=2026-08-30&date_to=2026-08-01');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date_range']);
    }
}
