<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Notification;
use App\Models\Permission;
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

        $sender->givePermissionTo(Permission::findOrCreate('can_transfer_documents', 'web'));
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

    public function test_force_notify_generates_notification(): void
    {
        $sender = $this->createUser('sender_user', 'Sender', 'User');
        $receiver = $this->createUser('receiver_user', 'Receiver', 'User');

        $document = Document::create([
            'id' => (string) Str::uuid(),
            'tracking_number' => 'DOC-FORCE-001',
            'created_by' => $sender->id,
            'origin_sender' => 'Sender Office',
            'sender_name' => 'Sender User',
            'document_type' => 'Letter',
            'priority_level' => 'High',
            'description' => 'Test document for force notification',
            'assigned_user' => $receiver->full_name,
            'status' => 'outgoing',
        ]);

        Sanctum::actingAs($sender);

        $response = $this->postJson("/api/documents/{$document->id}/force-notify");
        $response->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $receiver->id,
            'document_id' => $document->id,
            'type' => 'document.force_notify',
        ]);
    }

    public function test_deadline_command_sends_due_tomorrow_notifications_without_duplicates(): void
    {
        $user = $this->createUser('assigned_worker', 'Assigned', 'Worker');

        $dueTomorrowDoc = Document::create([
            'id' => (string) Str::uuid(),
            'tracking_number' => 'DOC-DUE-TOMORROW',
            'created_by' => $user->id,
            'origin_sender' => 'Office',
            'sender_name' => 'Sender',
            'document_type' => 'Report',
            'priority_level' => 'Urgent',
            'description' => 'Document due tomorrow',
            'assigned_user' => $user->full_name,
            'status' => 'outgoing',
            'due_date' => now()->addDay()->toDateString(),
        ]);

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

        $this->artisan('documents:send-deadline-notifications')
            ->assertExitCode(0);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'document_id' => $dueTomorrowDoc->id,
            'type' => 'document.due_tomorrow',
        ]);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $user->id,
            'document_id' => $dueIn3DaysDoc->id,
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
