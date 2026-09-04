<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentAssignment;
use App\Models\DocumentStatusHistory;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DocumentPerformancePersistenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--no-interaction' => true]);
    }

    public function test_documents_support_completion_assignment_history_and_legacy_nulls(): void
    {
        $creator = $this->makeUser('creator');
        $firstAssignee = $this->makeUser('first-assignee');
        $completingUser = $this->makeUser('completer');

        $document = Document::create([
            'tracking_number' => 'DOC-PERFORMANCE-001',
            'created_by' => $creator->id,
            'origin_sender' => 'Human Resources',
            'sender_name' => 'HR Office',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'Performance persistence test document',
            'status' => 'received',
            'received_at' => Carbon::parse('2026-08-01 09:00:00'),
            'due_at' => Carbon::parse('2026-08-05 17:00:00'),
        ]);

        $firstAssignment = DocumentAssignment::create([
            'document_id' => $document->id,
            'assigned_user_id' => $firstAssignee->id,
            'assigned_by_user_id' => $creator->id,
            'assigned_at' => Carbon::parse('2026-08-01 10:00:00'),
            'is_current' => false,
            'unassigned_at' => Carbon::parse('2026-08-02 11:00:00'),
            'notes' => 'Reassigned for workload balancing.',
        ]);

        $currentAssignment = DocumentAssignment::create([
            'document_id' => $document->id,
            'assigned_user_id' => $completingUser->id,
            'assigned_by_user_id' => $creator->id,
            'assigned_at' => Carbon::parse('2026-08-02 11:00:00'),
            'is_current' => true,
        ]);

        $document->update([
            'assigned_user_id' => $completingUser->id,
            'completed_at' => Carbon::parse('2026-08-04 15:00:00'),
            'completed_by_user_id' => $completingUser->id,
            'status' => 'completed',
        ]);

        DocumentStatusHistory::create([
            'document_id' => $document->id,
            'from_status' => 'received',
            'to_status' => 'assigned',
            'changed_by_user_id' => $creator->id,
            'changed_at' => Carbon::parse('2026-08-01 10:00:00'),
        ]);

        DocumentStatusHistory::create([
            'document_id' => $document->id,
            'from_status' => 'assigned',
            'to_status' => 'completed',
            'changed_by_user_id' => $completingUser->id,
            'changed_at' => Carbon::parse('2026-08-04 15:00:00'),
            'metadata' => ['source' => 'test'],
        ]);

        $document->load(['assignedUser', 'completedBy', 'assignments', 'statusHistory']);

        $this->assertSame($completingUser->id, $document->assignedUser->id);
        $this->assertSame($completingUser->id, $document->completedBy->id);
        $this->assertCount(2, $document->assignments);
        $this->assertSame($firstAssignee->id, $firstAssignment->assignedUser->id);
        $this->assertSame($completingUser->id, $currentAssignment->assignedUser->id);
        $this->assertCount(2, $document->statusHistory);
        $this->assertSame(['source' => 'test'], $document->statusHistory->last()->metadata);
        $this->assertCount(1, $firstAssignee->fresh()->documentAssignments);
        $this->assertCount(1, $completingUser->fresh()->completedDocuments);
        $this->assertCount(1, $completingUser->fresh()->statusHistoryChanges);

        $legacyDocument = Document::create([
            'tracking_number' => 'DOC-LEGACY-001',
            'created_by' => $creator->id,
            'origin_sender' => 'Human Resources',
            'sender_name' => 'HR Office',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'Legacy document without workflow fields',
            'status' => 'outgoing',
        ]);

        $this->assertNull($legacyDocument->assigned_user_id);
        $this->assertNull($legacyDocument->received_at);
        $this->assertNull($legacyDocument->due_at);
        $this->assertNull($legacyDocument->completed_at);
        $this->assertNull($legacyDocument->completed_by_user_id);
    }

    private function makeUser(string $username): User
    {
        return User::create([
            'username' => $username,
            'password' => 'password',
            'first_name' => 'Test',
            'last_name' => $username,
        ]);
    }
}
