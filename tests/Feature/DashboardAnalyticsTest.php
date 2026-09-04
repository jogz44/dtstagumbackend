<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use App\Services\DocumentWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected Office $office;

    protected DocumentWorkflowService $workflowService;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->office = Office::create([
            'id' => (string) Str::uuid(),
            'name' => 'HR Division',
            'short_name' => 'HRD',
            'code' => 'HRD-'.Str::random(4),
        ]);

        $this->adminUser = User::create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Admin',
            'last_name' => 'Tester',
            'username' => 'admin_tester_'.Str::random(4),
            'email' => 'admin_'.Str::random(4).'@example.com',
            'password' => Hash::make('password'),
            'must_change_password' => false,
            'office_id' => $this->office->id,
            'is_active' => true,
        ]);
        $this->adminUser->assignRole($adminRole);

        $this->workflowService = app(DocumentWorkflowService::class);
    }

    private function createTestUser(Office $office): User
    {
        return User::create([
            'id' => (string) Str::uuid(),
            'first_name' => 'User',
            'last_name' => Str::random(4),
            'username' => 'user_'.Str::random(6),
            'email' => 'user_'.Str::random(6).'@example.com',
            'password' => Hash::make('password'),
            'must_change_password' => false,
            'office_id' => $office->id,
            'is_active' => true,
        ]);
    }

    private function createTestDocument(array $attributes = []): Document
    {
        return Document::create(array_merge([
            'id' => (string) Str::uuid(),
            'tracking_number' => 'DOC-'.Str::random(8),
            'created_by' => $this->adminUser->id,
            'origin_sender' => 'City HR',
            'sender_name' => 'Officer',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'Test document',
            'status' => 'pending',
            'office_assignment' => 'HR Division',
        ], $attributes));
    }

    /** 1. Received -> completed document remains included in annual received count */
    public function test_received_then_completed_document_remains_in_annual_received_count(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $doc = $this->createTestDocument([
            'received_at' => Carbon::create(2026, 3, 15, 10, 0, 0, 'Asia/Manila'),
            'status' => 'pending',
        ]);

        $this->workflowService->transition($doc, 'completed');

        $response = $this->getJson('/api/dashboard/analytics?year=2026');

        $response->assertStatus(200)
            ->assertJsonPath('yearly_overview.received_total', 1)
            ->assertJsonPath('yearly_overview.completed_total', 1);
    }

    /** 2. Dec 31 / Jan 1 year boundary test */
    public function test_year_boundary_filtering(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        // Dec 31, 2026 23:59:59
        $this->createTestDocument([
            'received_at' => Carbon::create(2026, 12, 31, 23, 59, 59, 'Asia/Manila'),
            'status' => 'pending',
        ]);

        // Jan 1, 2027 00:00:00
        $this->createTestDocument([
            'received_at' => Carbon::create(2027, 1, 1, 0, 0, 0, 'Asia/Manila'),
            'status' => 'pending',
        ]);

        $res2026 = $this->getJson('/api/dashboard/analytics?year=2026');
        $res2026->assertStatus(200)->assertJsonPath('yearly_overview.received_total', 1);

        $res2027 = $this->getJson('/api/dashboard/analytics?year=2027');
        $res2027->assertStatus(200)->assertJsonPath('yearly_overview.received_total', 1);
    }

    /** 3. received_at is immutable after initial reception */
    public function test_received_at_is_immutable_on_further_transitions(): void
    {
        $initialTime = Carbon::create(2026, 4, 10, 9, 0, 0, 'Asia/Manila');

        $doc = $this->createTestDocument([
            'received_at' => $initialTime,
            'status' => 'pending',
        ]);

        $this->workflowService->transition($doc, 'in_process');
        $this->workflowService->transition($doc, 'completed');

        $this->assertEquals($initialTime->format('Y-m-d H:i:s'), $doc->fresh()->received_at->format('Y-m-d H:i:s'));
    }

    /** 4. Receipt today increments Received Today count */
    public function test_receipt_today_increments_received_today(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $this->createTestDocument([
            'received_at' => Carbon::now('Asia/Manila'),
            'status' => 'pending',
        ]);

        $response = $this->getJson('/api/dashboard/analytics');

        $response->assertStatus(200)
            ->assertJsonPath('yearly_overview.received_today', 1);
    }

    /** 5. Receipt yesterday does not increment Received Today count */
    public function test_receipt_yesterday_does_not_increment_received_today(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $this->createTestDocument([
            'received_at' => Carbon::yesterday('Asia/Manila')->startOfDay(),
            'status' => 'pending',
        ]);

        $response = $this->getJson('/api/dashboard/analytics');

        $response->assertStatus(200)
            ->assertJsonPath('yearly_overview.received_today', 0);
    }

    /** 6. Year parameter excludes documents from other years */
    public function test_year_parameter_isolates_documents(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $this->createTestDocument([
            'received_at' => Carbon::create(2025, 6, 1, 10, 0, 0, 'Asia/Manila'),
            'status' => 'pending',
        ]);

        $response = $this->getJson('/api/dashboard/analytics?year=2026');

        $response->assertStatus(200)
            ->assertJsonPath('yearly_overview.received_total', 0);
    }

    /** 7. Completing a document later preserves original received_at */
    public function test_completing_document_preserves_original_received_at(): void
    {
        $receiptTime = Carbon::create(2026, 2, 1, 8, 0, 0, 'Asia/Manila');

        $doc = $this->createTestDocument([
            'received_at' => $receiptTime,
            'status' => 'pending',
        ]);

        Carbon::setTestNow(Carbon::create(2026, 3, 1, 12, 0, 0, 'Asia/Manila'));
        $this->workflowService->transition($doc, 'completed');
        Carbon::setTestNow();

        $fresh = $doc->fresh();
        $this->assertEquals($receiptTime->format('Y-m-d H:i:s'), $fresh->received_at->format('Y-m-d H:i:s'));
        $this->assertNotNull($fresh->completed_at);
    }

    /** 8. Updating metadata of completed document in a later year does not alter completed_at */
    public function test_updating_metadata_does_not_alter_completed_at(): void
    {
        Sanctum::actingAs($this->adminUser, ['*']);

        $completionTime = Carbon::create(2026, 11, 15, 14, 0, 0, 'Asia/Manila');

        $doc = $this->createTestDocument([
            'received_at' => Carbon::create(2026, 11, 1, 10, 0, 0, 'Asia/Manila'),
            'completed_at' => $completionTime,
            'status' => 'completed',
            'remarks' => 'Original completion remarks',
        ]);

        // Edit metadata in 2027
        Carbon::setTestNow(Carbon::create(2027, 1, 10, 10, 0, 0, 'Asia/Manila'));
        $this->putJson("/api/documents/{$doc->id}", [
            'remarks' => 'Updated metadata in 2027',
        ]);
        Carbon::setTestNow();

        $fresh = $doc->fresh();
        $this->assertEquals($completionTime->format('Y-m-d H:i:s'), $fresh->completed_at->format('Y-m-d H:i:s'));

        // Verify 2026 has +1 completed, 2027 has +0 completed
        $res2026 = $this->getJson('/api/dashboard/analytics?year=2026');
        $res2026->assertStatus(200)->assertJsonPath('yearly_overview.completed_total', 1);

        $res2027 = $this->getJson('/api/dashboard/analytics?year=2027');
        $res2027->assertStatus(200)->assertJsonPath('yearly_overview.completed_total', 0);
    }

    /** 9. Security test: Server enforces office/role visibility scoping */
    public function test_server_enforces_office_visibility_scoping(): void
    {
        $officeA = Office::create(['id' => (string) Str::uuid(), 'name' => 'Office Alpha', 'short_name' => 'OA', 'code' => 'OA-'.Str::random(4)]);
        $officeB = Office::create(['id' => (string) Str::uuid(), 'name' => 'Office Beta', 'short_name' => 'OB', 'code' => 'OB-'.Str::random(4)]);

        $userA = $this->createTestUser($officeA);
        $userB = $this->createTestUser($officeB);

        // Document in Office Alpha
        $this->createTestDocument([
            'created_by' => $userA->id,
            'office_assignment' => 'Office Alpha',
            'received_at' => Carbon::now('Asia/Manila'),
            'status' => 'pending',
        ]);

        // Document in Office Beta
        $this->createTestDocument([
            'created_by' => $userB->id,
            'office_assignment' => 'Office Beta',
            'received_at' => Carbon::now('Asia/Manila'),
            'status' => 'pending',
        ]);

        // User A should only see Office Alpha's 1 document
        Sanctum::actingAs($userA, ['*']);
        $resA = $this->getJson('/api/dashboard/analytics');
        $resA->assertStatus(200)->assertJsonPath('yearly_overview.received_total', 1);

        // User B should only see Office Beta's 1 document
        Sanctum::actingAs($userB, ['*']);
        $resB = $this->getJson('/api/dashboard/analytics');
        $resB->assertStatus(200)->assertJsonPath('yearly_overview.received_total', 1);
    }
}
