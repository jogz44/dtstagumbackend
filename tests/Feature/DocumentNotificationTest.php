<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Notification;
use App\Models\User;
use App\Services\DocumentNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_receiver_gets_notification_when_document_is_transferred(): void
    {
        $sender = $this->createUser('sender_user', 'Sender', 'User');
        $receiver = $this->createUser('receiver_user', 'Receiver', 'User');

        $document = Document::create([
            'id' => (string) Str::uuid(),
            'tracking_number' => 'DOC-TEST-001',
            'created_by' => $sender->id,
            'origin_sender' => 'Sender Office',
            'sender_name' => 'Sender User',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'Test document for transfer',
            'status' => 'registered',
        ]);

        Sanctum::actingAs($sender);

        $response = $this->postJson("/api/documents/{$document->id}/transfer", [
            'assigned_user' => $receiver->full_name,
            'office_assignment' => 'Receiver Office',
            'remarks' => 'Please review and process.',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $receiver->id,
            'document_id' => $document->id,
            'type' => 'document.received',
        ]);

        $this->assertSame(1, Notification::query()
            ->where('user_id', $receiver->id)
            ->where('document_id', $document->id)
            ->whereNull('read_at')
            ->count());

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $receiver->id,
            'document_id' => $document->id,
            'type' => 'document.pending',
        ]);
    }

    public function test_creator_gets_notification_when_document_is_completed(): void
    {
        $creator = $this->createUser('creator_user', 'Creator', 'User');
        $processor = $this->createUser('processor_user', 'Processor', 'User');

        $document = Document::create([
            'id' => (string) Str::uuid(),
            'tracking_number' => 'DOC-TEST-002',
            'created_by' => $creator->id,
            'origin_sender' => 'Creator Office',
            'sender_name' => 'Creator User',
            'document_type' => 'Letter',
            'priority_level' => 'High',
            'description' => 'Test document for completion',
            'status' => 'outgoing',
        ]);

        Sanctum::actingAs($processor);

        $response = $this->postJson("/api/documents/{$document->id}/complete", [
            'remarks' => 'Completed action.',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $creator->id,
            'document_id' => $document->id,
            'type' => 'document.approved',
        ]);
    }

    public function test_deadline_command_sends_due_soon_and_overdue_notifications_without_duplicates(): void
    {
        $user = $this->createUser('assigned_worker', 'Assigned', 'Worker');

        $dueIn3DaysDoc = Document::create([
            'id' => (string) Str::uuid(),
            'tracking_number' => 'DOC-DUE-3',
            'created_by' => $user->id,
            'origin_sender' => 'Office',
            'sender_name' => 'Sender',
            'document_type' => 'Report',
            'priority_level' => 'Urgent',
            'description' => 'Document due in 3 days',
            'assigned_user' => $user->full_name,
            'status' => 'outgoing',
            'due_date' => now()->addDays(3)->toDateString(),
        ]);

        $overdueDoc = Document::create([
            'id' => (string) Str::uuid(),
            'tracking_number' => 'DOC-OVERDUE',
            'created_by' => $user->id,
            'origin_sender' => 'Office',
            'sender_name' => 'Sender',
            'document_type' => 'Report',
            'priority_level' => 'Urgent',
            'description' => 'Overdue document',
            'assigned_user' => $user->full_name,
            'status' => 'outgoing',
            'due_date' => now()->subDays(2)->toDateString(),
        ]);

        $this->artisan('documents:send-deadline-notifications')
            ->assertExitCode(0);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'document_id' => $dueIn3DaysDoc->id,
            'type' => 'document.due_soon',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'document_id' => $overdueDoc->id,
            'type' => 'document.overdue',
        ]);

        // Second run should be idempotent and not create duplicate records
        $countBefore = Notification::count();
        $this->artisan('documents:send-deadline-notifications')
            ->assertExitCode(0);
        $this->assertEquals($countBefore, Notification::count());
    }

    public function test_user_can_only_access_their_own_notifications_via_api(): void
    {
        $userA = $this->createUser('usera', 'User', 'A');
        $userB = $this->createUser('userb', 'User', 'B');

        $notifA = Notification::create([
            'id' => (string) Str::uuid(),
            'user_id' => $userA->id,
            'type' => 'document.received',
            'title' => 'Title A',
            'message' => 'Message for A',
            'notification_key' => 'key_a',
        ]);

        $notifB = Notification::create([
            'id' => (string) Str::uuid(),
            'user_id' => $userB->id,
            'type' => 'document.received',
            'title' => 'Title B',
            'message' => 'Message for B',
            'notification_key' => 'key_b',
        ]);

        Sanctum::actingAs($userA);

        $indexRes = $this->getJson('/api/me/notifications');
        $indexRes->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $notifA->id);

        $unreadRes = $this->getJson('/api/me/notifications/unread-count');
        $unreadRes->assertOk()->assertJsonPath('unread_count', 1);

        // User A trying to mark User B's notification as read should return 404
        $markRes = $this->postJson("/api/me/notifications/{$notifB->id}/read");
        $markRes->assertNotFound();

        // User A marking own notification as read should succeed
        $markOwnRes = $this->postJson("/api/me/notifications/{$notifA->id}/read");
        $markOwnRes->assertOk()->assertJsonPath('unread_count', 0);
    }

    public function test_assignment_notification_is_idempotent_per_event_and_allows_a_later_assignment(): void
    {
        $sender = $this->createUser('event_sender', 'Event', 'Sender');
        $receiver = $this->createUser('event_receiver', 'Event', 'Receiver');
        $document = Document::create([
            'id' => (string) Str::uuid(),
            'tracking_number' => 'DOC-EVENT-001',
            'created_by' => $sender->id,
            'origin_sender' => 'Sender Office',
            'sender_name' => $sender->full_name,
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'Repeat assignment test',
            'status' => 'pending',
        ]);
        $notificationService = app(DocumentNotificationService::class);
        $firstEventId = (string) Str::uuid();
        $laterEventId = (string) Str::uuid();

        $notificationService->notifyReceived($document, $receiver, $sender, $firstEventId);
        $notificationService->notifyReceived($document, $receiver, $sender, $firstEventId);
        $notificationService->notifyReceived($document, $receiver, $sender, $laterEventId);

        $this->assertSame(2, Notification::query()
            ->where('user_id', $receiver->id)
            ->where('document_id', $document->id)
            ->where('type', 'document.received')
            ->count());
    }

    protected function createUser(string $username, string $firstName, string $lastName): User
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
}
