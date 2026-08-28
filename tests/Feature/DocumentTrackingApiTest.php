<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentHistory;
use App\Models\Office;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentTrackingApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $userA;
    private User $userB;
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

        $viewConfidential = Permission::firstOrCreate(['name' => 'documents.view_confidential', 'guard_name' => 'web']);
        $adminRole->givePermissionTo($viewConfidential);

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
        $this->userB->assignRole($supervisorRole);
    }

    public function test_full_document_tracking_lifecycle_from_registered_to_completed(): void
    {
        Sanctum::actingAs($this->userA);

        $baseTime = now()->subMinutes(10);

        // 1. Registered Stage
        $document = Document::create([
            'tracking_number' => 'DOC-2026-TRACK-001',
            'description' => 'Budget Request for Equipment',
            'document_type' => 'Request',
            'priority_level' => 'Normal',
            'origin_sender' => $this->officeA->name,
            'sender_name' => $this->userA->full_name,
            'office_assignment' => $this->officeA->name,
            'assigned_user' => $this->userA->full_name,
            'created_by' => $this->userA->id,
            'status' => 'registered',
            'due_date' => now()->addDays(2)->format('Y-m-d'),
        ]);

        $h1 = DocumentHistory::record($document, DocumentHistory::REGISTERED, $this->userA, [
            'remarks' => 'Document registered in system.',
            'from_office' => $this->officeA->name,
            'to_office' => $this->officeA->name,
            'status_after' => 'registered',
        ]);
        $h1->forceFill(['created_at' => $baseTime])->save();

        $response1 = $this->getJson("/api/documents/{$document->id}/tracking");
        $response1->assertOk()
            ->assertJsonPath('document.document_number', 'DOC-2026-TRACK-001')
            ->assertJsonPath('tracking.stage', 'registered')
            ->assertJsonPath('tracking.workflow_status', 'registered')
            ->assertJsonPath('tracking.deadline.is_due_soon', true)
            ->assertJsonPath('tracking.deadline.is_overdue', false);

        // 2. Transferred / Forwarded Stage
        $document->update([
            'office_assignment' => $this->officeB->name,
            'assigned_user' => $this->userB->full_name,
            'status' => 'outgoing',
        ]);

        $document->acknowledgements()->create([
            'user_id' => $this->userB->id,
            'assigned_name' => $this->userB->full_name,
            'from_office' => $this->officeA->name,
            'remarks' => 'Pending acknowledgement.',
        ]);

        $h2 = DocumentHistory::record($document, DocumentHistory::TRANSFERRED, $this->userA, [
            'remarks' => 'Document transferred to City Administrator Office.',
            'from_office' => $this->officeA->name,
            'to_office' => $this->officeB->name,
            'status_before' => 'registered',
            'status_after' => 'outgoing',
        ]);
        $h2->forceFill(['created_at' => $baseTime->copy()->addMinutes(2)])->save();

        $response2 = $this->getJson("/api/documents/{$document->id}/tracking");
        $response2->assertOk()
            ->assertJsonPath('tracking.stage', 'routed')
            ->assertJsonPath('tracking.current_holder.name', $this->userB->full_name)
            ->assertJsonPath('tracking.current_location.office', $this->officeB->name);

        // 3. Received / Acknowledged Stage by User B
        Sanctum::actingAs($this->userB);

        $ack = $document->acknowledgements()->where('user_id', $this->userB->id)->first();
        $ack->update(['acknowledged_at' => $baseTime->copy()->addMinutes(4)]);

        $h3 = DocumentHistory::record($document, DocumentHistory::RECEIVED, $this->userB, [
            'remarks' => 'Document received by Maria Santos.',
            'from_office' => $this->officeA->name,
            'to_office' => $this->officeB->name,
            'status_before' => 'outgoing',
            'status_after' => 'incoming',
        ]);
        $h3->forceFill(['created_at' => $baseTime->copy()->addMinutes(4)])->save();

        $response3 = $this->getJson("/api/documents/{$document->id}/tracking");
        $response3->assertOk()
            ->assertJsonPath('tracking.stage', 'received')
            ->assertJsonPath('tracking.current_holder.name', $this->userB->full_name);

        // 4. Overdue condition check (derived deadline without altering status)
        $document->update(['due_date' => now()->subDays(2)->format('Y-m-d')]);

        $response4 = $this->getJson("/api/documents/{$document->id}/tracking");
        $response4->assertOk()
            ->assertJsonPath('tracking.workflow_status', 'outgoing') // retains real status
            ->assertJsonPath('tracking.deadline.is_overdue', true)
            ->assertJsonPath('tracking.deadline.days_overdue', 2);

        // 5. Completion
        $document->update(['status' => 'completed']);
        $h4 = DocumentHistory::record($document, DocumentHistory::COMPLETED, $this->userB, [
            'remarks' => 'Approved and completed.',
            'status_after' => 'completed',
        ]);
        $h4->forceFill(['created_at' => $baseTime->copy()->addMinutes(6)])->save();

        $response5 = $this->getJson("/api/documents/{$document->id}/tracking");
        $response5->assertOk()
            ->assertJsonPath('tracking.stage', 'completed')
            ->assertJsonPath('tracking.workflow_status', 'completed')
            ->assertJsonPath('tracking.deadline.is_overdue', false);
    }

    public function test_document_tracking_index_returns_role_scoped_records_and_statistics(): void
    {
        // Create Document 1 (User A created, sent to User B)
        $doc1 = Document::create([
            'tracking_number' => 'DOC-LIST-001',
            'description' => 'User A Outbound Purchase Request',
            'document_type' => 'Purchase Request',
            'priority_level' => 'Normal',
            'origin_sender' => $this->officeA->name,
            'sender_name' => $this->userA->full_name,
            'office_assignment' => $this->officeB->name,
            'assigned_user' => $this->userB->full_name,
            'created_by' => $this->userA->id,
            'status' => 'outgoing',
            'due_date' => now()->addDays(3)->format('Y-m-d'),
        ]);

        DocumentHistory::record($doc1, DocumentHistory::REGISTERED, $this->userA, [
            'from_office' => $this->officeA->name,
            'to_office' => $this->officeB->name,
            'status_after' => 'outgoing',
        ]);

        // Create Document 2 (User B created, sent to User A)
        $doc2 = Document::create([
            'tracking_number' => 'DOC-LIST-002',
            'description' => 'User B Incoming Notice for User A',
            'document_type' => 'Notice',
            'priority_level' => 'High',
            'origin_sender' => $this->officeB->name,
            'sender_name' => $this->userB->full_name,
            'office_assignment' => $this->officeA->name,
            'assigned_user' => $this->userA->full_name,
            'created_by' => $this->userB->id,
            'status' => 'pending',
            'due_date' => now()->subDays(1)->format('Y-m-d'), // Overdue
        ]);

        DocumentHistory::record($doc2, DocumentHistory::REGISTERED, $this->userB, [
            'from_office' => $this->officeB->name,
            'to_office' => $this->officeA->name,
            'status_after' => 'pending',
        ]);

        // 1. User A checks statistics & index listing
        Sanctum::actingAs($this->userA);

        $statsRes = $this->getJson('/api/document-tracking/statistics');
        $statsRes->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('incoming', 1)
            ->assertJsonPath('outgoing', 1)
            ->assertJsonPath('overdue', 1);

        $indexRes = $this->getJson('/api/document-tracking?scope=all&status=all');
        $indexRes->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('pagination.total', 2);

        // Test Scope = incoming for User A
        $incRes = $this->getJson('/api/document-tracking?scope=incoming');
        $incRes->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.document_number', 'DOC-LIST-002')
            ->assertJsonPath('data.0.direction', 'incoming');

        // Test Scope = outgoing for User A
        $outRes = $this->getJson('/api/document-tracking?scope=outgoing');
        $outRes->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.document_number', 'DOC-LIST-001')
            ->assertJsonPath('data.0.direction', 'outgoing');

        // Test Status = overdue for User A
        $overdueRes = $this->getJson('/api/document-tracking?status=overdue');
        $overdueRes->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.document_number', 'DOC-LIST-002');
    }

    public function test_unfiltered_tracking_list_total_matches_statistics_total_for_admin_and_users(): void
    {
        Document::create([
            'tracking_number' => 'DOC-ADM-001',
            'description' => 'Admin Document 1',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'origin_sender' => $this->officeA->name,
            'sender_name' => $this->userA->full_name,
            'created_by' => $this->userA->id,
            'status' => 'REGISTERED',
        ]);

        Document::create([
            'tracking_number' => 'DOC-ADM-002',
            'description' => 'Admin Document 2',
            'document_type' => 'Letter',
            'priority_level' => 'High',
            'origin_sender' => $this->officeB->name,
            'sender_name' => $this->userB->full_name,
            'created_by' => $this->userB->id,
            'status' => 'In Progress',
        ]);

        // 1. Admin Verification
        Sanctum::actingAs($this->admin);
        $adminStats = $this->getJson('/api/document-tracking/statistics')->json();
        $adminList = $this->getJson('/api/document-tracking?scope=all&status=all&page=1&per_page=25')->json();

        $this->assertEquals($adminStats['total'], $adminList['pagination']['total']);
        $this->assertCount($adminStats['total'], $adminList['data']);

        // 2. User A Verification
        Sanctum::actingAs($this->userA);
        $userStats = $this->getJson('/api/document-tracking/statistics')->json();
        $userList = $this->getJson('/api/document-tracking?scope=all&status=all&page=1&per_page=25')->json();

        $this->assertEquals($userStats['total'], $userList['pagination']['total']);
        $this->assertCount($userStats['total'], $userList['data']);

        // 3. User B Verification
        Sanctum::actingAs($this->userB);
        $userBStats = $this->getJson('/api/document-tracking/statistics')->json();
        $userBList = $this->getJson('/api/document-tracking?scope=all&status=all&page=1&per_page=25')->json();

        $this->assertEquals($userBStats['total'], $userBList['pagination']['total']);
        $this->assertCount($userBStats['total'], $userBList['data']);
    }

    public function test_deactivated_historical_users_remain_visible_in_timeline(): void
    {
        $document = Document::create([
            'tracking_number' => 'DOC-HIST-001',
            'description' => 'Historical Test Document',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'origin_sender' => $this->officeA->name,
            'sender_name' => $this->userA->full_name,
            'created_by' => $this->userA->id,
            'status' => 'registered',
        ]);

        DocumentHistory::record($document, DocumentHistory::REGISTERED, $this->userA, [
            'remarks' => 'Registered by Juan.',
        ]);

        // Soft-delete userA
        $this->userA->delete();

        Sanctum::actingAs($this->admin);

        $response = $this->getJson("/api/documents/{$document->id}/tracking");
        $response->assertOk()
            ->assertJsonPath('timeline.0.actor.name', 'Juan Dela Cruz')
            ->assertJsonPath('timeline.0.actor.id', $this->userA->id);
    }

    public function test_tracking_search_respects_authorization_and_confidentiality(): void
    {
        $docPublic = Document::create([
            'tracking_number' => 'DOC-PUBLIC-99',
            'description' => 'Public Ordinance Document',
            'document_type' => 'Ordinance',
            'priority_level' => 'Normal',
            'origin_sender' => $this->officeA->name,
            'sender_name' => $this->userA->full_name,
            'office_assignment' => $this->officeA->name,
            'created_by' => $this->userA->id,
            'is_confidential' => false,
            'status' => 'registered',
        ]);

        $docConfidential = Document::create([
            'tracking_number' => 'DOC-CONF-88',
            'description' => 'Secret Audit Report',
            'document_type' => 'Report',
            'priority_level' => 'High',
            'origin_sender' => $this->officeB->name,
            'sender_name' => $this->userB->full_name,
            'office_assignment' => $this->officeB->name,
            'created_by' => $this->userB->id,
            'is_confidential' => true,
            'status' => 'registered',
        ]);

        // User A searches
        Sanctum::actingAs($this->userA);

        $res1 = $this->getJson('/api/document-tracking/search?q=Public');
        $res1->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.document_number', 'DOC-PUBLIC-99');

        $res2 = $this->getJson('/api/document-tracking/search?q=Secret');
        $res2->assertOk()
            ->assertJsonCount(0, 'data'); // Excluded due to confidentiality and user A non-ownership

        // Admin searches confidential doc
        Sanctum::actingAs($this->admin);
        $res3 = $this->getJson('/api/document-tracking/search?q=Secret');
        $res3->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.document_number', 'DOC-CONF-88');
    }

    public function test_unauthorized_user_cannot_track_document(): void
    {
        $docSecret = Document::create([
            'tracking_number' => 'DOC-PRIVATE-01',
            'description' => 'Private Office B Doc',
            'document_type' => 'Letter',
            'priority_level' => 'Normal',
            'origin_sender' => $this->officeB->name,
            'sender_name' => $this->userB->full_name,
            'office_assignment' => $this->officeB->name,
            'created_by' => $this->userB->id,
            'is_confidential' => false,
            'status' => 'registered',
        ]);

        Sanctum::actingAs($this->userA);

        $response = $this->getJson("/api/documents/{$docSecret->id}/tracking");
        $response->assertStatus(403);
    }
}
