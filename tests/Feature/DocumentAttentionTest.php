<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentAcknowledgement;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentAttentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_attention_summary_due_count_matches_due_document_filter(): void
    {
        $viewer = $this->createUser('viewer', 'Attention', 'Viewer');
        $otherUser = $this->createUser('other', 'Other', 'User');

        $dueToday = $this->createDocument($viewer, [
            'tracking_number' => 'DOC-DUE-TODAY',
            'due_date' => today()->toDateString(),
        ]);
        $overdue = $this->createDocument($viewer, [
            'tracking_number' => 'DOC-OVERDUE',
            'due_date' => today()->subDay()->toDateString(),
        ]);
        $this->createDocument($viewer, ['tracking_number' => 'DOC-FUTURE', 'due_date' => today()->addDay()->toDateString()]);
        $this->createDocument($viewer, ['tracking_number' => 'DOC-NO-DATE', 'due_date' => null]);
        $this->createDocument($viewer, [
            'tracking_number' => 'DOC-COMPLETED',
            'status' => 'completed',
            'due_date' => today()->subDay()->toDateString(),
        ]);
        $this->createDocument($otherUser, [
            'tracking_number' => 'DOC-UNRELATED',
            'due_date' => today()->subDay()->toDateString(),
        ]);

        DocumentAcknowledgement::create([
            'document_id' => $dueToday->id,
            'user_id' => $viewer->id,
            'assigned_name' => $viewer->full_name,
        ]);
        Notification::create([
            'user_id' => $viewer->id,
            'document_id' => $dueToday->id,
            'type' => 'document.overdue',
            'title' => 'Due',
            'message' => 'Due now',
            'notification_key' => 'attention-summary-notification',
        ]);

        Sanctum::actingAs($viewer);

        $summaryResponse = $this->getJson('/api/me/attention-summary');
        $documentsResponse = $this->getJson('/api/documents?attention=due');

        $summaryResponse->assertOk()
            ->assertJsonPath('due_documents', 2)
            ->assertJsonPath('pending_documents', 4)
            ->assertJsonPath('unopened_assignments', 1)
            ->assertJsonPath('unread_notifications', 1);
        $documentsResponse->assertOk()->assertJsonCount(2, 'data');

        $this->assertSame($summaryResponse->json('due_documents'), count($documentsResponse->json('data')));
        $this->assertEqualsCanonicalizing(
            [$dueToday->id, $overdue->id],
            collect($documentsResponse->json('data'))->pluck('id')->all(),
        );
    }

    public function test_acknowledging_document_marks_only_current_users_assignment_notifications_read(): void
    {
        $viewer = $this->createUser('viewer_read', 'Read', 'Viewer');
        $otherUser = $this->createUser('other_read', 'Read', 'Other');
        $document = $this->createDocument($viewer);

        DocumentAcknowledgement::create([
            'document_id' => $document->id,
            'user_id' => $viewer->id,
            'assigned_name' => $viewer->full_name,
        ]);

        $viewerAssignment = $this->createNotification($viewer, $document, 'document.received', 'viewer-assignment');
        $viewerDeadline = $this->createNotification($viewer, $document, 'document.overdue', 'viewer-deadline');
        $otherAssignment = $this->createNotification($otherUser, $document, 'document.received', 'other-assignment');

        Sanctum::actingAs($viewer);

        $this->postJson("/api/documents/{$document->id}/acknowledge")
            ->assertOk()
            ->assertJsonPath('unread_notifications', 1);

        $this->assertNotNull($viewerAssignment->fresh()->read_at);
        $this->assertNull($viewerDeadline->fresh()->read_at);
        $this->assertNull($otherAssignment->fresh()->read_at);
    }

    public function test_unrelated_user_cannot_open_acknowledge_or_fetch_attachment_for_document(): void
    {
        $owner = $this->createUser('owner', 'Document', 'Owner');
        $unrelatedUser = $this->createUser('outsider', 'Outside', 'User');
        $document = $this->createDocument($owner);
        $attachment = $document->attachments()->create([
            'original_name' => 'private.pdf',
            'stored_name' => 'private.pdf',
            'path' => 'documents/private.pdf',
            'disk' => 'shared_documents',
            'mime_type' => 'application/pdf',
            'size' => 100,
        ]);

        Sanctum::actingAs($unrelatedUser);

        $this->getJson("/api/documents/{$document->id}")->assertForbidden();
        $this->postJson("/api/documents/{$document->id}/acknowledge")->assertForbidden();
        $this->getJson("/api/attachments/{$attachment->id}")->assertForbidden();
        $this->getJson("/api/attachments/{$attachment->id}/public")->assertNotFound();
    }

    private function createUser(string $username, string $firstName, string $lastName): User
    {
        return User::create([
            'id' => (string) Str::uuid(),
            'username' => $username,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'password' => Hash::make('password123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function createDocument(User $assignedUser, array $attributes = []): Document
    {
        return Document::create(array_merge([
            'id' => (string) Str::uuid(),
            'tracking_number' => 'DOC-'.Str::upper(Str::random(8)),
            'created_by' => $assignedUser->id,
            'origin_sender' => 'Origin Office',
            'sender_name' => 'Sender',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'Attention test document',
            'assigned_user' => $assignedUser->full_name,
            'status' => 'pending',
        ], $attributes));
    }

    private function createNotification(User $user, Document $document, string $type, string $key): Notification
    {
        return Notification::create([
            'user_id' => $user->id,
            'document_id' => $document->id,
            'type' => $type,
            'title' => 'Notification',
            'message' => 'Notification message',
            'notification_key' => $key,
        ]);
    }
}
