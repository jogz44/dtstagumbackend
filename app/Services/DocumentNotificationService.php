<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Notification;
use App\Models\User;
use App\Support\DocumentStatuses;
use Illuminate\Database\QueryException;

class DocumentNotificationService
{
    /**
     * Canonical document statuses where actions/deadlines are no longer required.
     */
    public const NON_ACTIONABLE_STATUSES = DocumentStatuses::TERMINAL;

    /**
     * Notify user when an admin or supervisor force-notifies a document to them.
     */
    public function notifyForce(
        Document $document,
        User $receiver,
        ?User $sender = null
    ): ?Notification {
        if ($sender && $receiver->id === $sender->id) {
            return null;
        }

        $key = "doc:{$document->id}:force:user:{$receiver->id}:" . now()->timestamp;
        $senderName = $sender?->full_name ?: $sender?->username ?: 'Administrator';
        $trackingNo = $document->tracking_number;

        return $this->createIfNotExists([
            'user_id' => $receiver->id,
            'document_id' => $document->id,
            'type' => 'document.force_notify',
            'title' => 'Force notification',
            'message' => "{$trackingNo} requires your immediate action (sent by {$senderName}).",
            'notification_key' => $key,
            'metadata' => [
                'tracking_number' => $trackingNo,
                'sender_id' => $sender?->id,
                'sender_name' => $senderName,
            ],
        ]);
    }

    /**
     * Notify user when a document is assigned or routed to them.
     */
    public function notifyReceived(
        Document $document,
        User $receiver,
        ?User $sender = null,
        ?string $assignmentEventId = null,
    ): ?Notification {
        // Do not notify if receiver is the sender (self assignment / self transmission)
        if ($sender && $receiver->id === $sender->id) {
            return null;
        }

        // Do not notify if receiver is the document creator and document entry / initial self-assignment occurs
        if ($receiver->id === $document->created_by && ($sender === null || $sender->id === $document->created_by)) {
            return null;
        }

        $eventKey = $assignmentEventId ?: 'legacy';
        $key = "doc:{$document->id}:assignment:{$eventKey}:user:{$receiver->id}";

        $senderName = $sender?->full_name ?: $document->origin_sender ?: 'Sender';
        $trackingNo = $document->tracking_number;

        return $this->createIfNotExists([
            'user_id' => $receiver->id,
            'document_id' => $document->id,
            'type' => 'document.received',
            'title' => 'New document received',
            'message' => "{$trackingNo} has been assigned to you by {$senderName}.",
            'notification_key' => $key,
            'metadata' => [
                'tracking_number' => $trackingNo,
                'sender_id' => $sender?->id,
                'sender_name' => $senderName,
                'assignment_event_id' => $assignmentEventId,
            ],
        ]);
    }

    /**
     * Notify user who started/received the document first when it is completed.
     */
    public function notifyApproved(Document $document, User $actor): ?Notification
    {
        $starterUserId = $document->created_by;

        if (! $starterUserId) {
            $earliestHistory = \App\Models\DocumentHistory::where('document_id', $document->id)
                ->whereNotNull('user_id')
                ->orderBy('created_at', 'asc')
                ->first();
            $starterUserId = $earliestHistory?->user_id;
        }

        if (! $starterUserId) {
            return null;
        }

        // Do not self-notify if the actor who completed the document is the starter user themselves
        if ($starterUserId === $actor->id) {
            return null;
        }

        $key = "doc:{$document->id}:completed:user:{$starterUserId}";
        $trackingNo = $document->tracking_number;
        $actorName = $actor->full_name ?: $actor->username ?: 'A user';

        return $this->createIfNotExists([
            'user_id' => $starterUserId,
            'document_id' => $document->id,
            'type' => 'document.completed',
            'title' => 'Document completed',
            'message' => "{$trackingNo} that started with you was marked as completed by {$actorName}.",
            'notification_key' => $key,
            'metadata' => [
                'tracking_number' => $trackingNo,
                'completed_by' => $actor->id,
                'completed_by_name' => $actorName,
            ],
        ]);
    }

    /**
     * Notify current responsible user that a document requires their attention/action (disabled per notification policy).
     */
    public function notifyPending(Document $document, User $responsibleUser): ?Notification
    {
        return null;
    }

    /**
     * Notify responsible user when a document is due tomorrow.
     */
    public function notifyDueSoon(Document $document, User $responsibleUser, int $days): ?Notification
    {
        if ($days !== 1) {
            return null;
        }

        if (in_array(strtolower((string) $document->status), self::NON_ACTIONABLE_STATUSES, true)) {
            return null;
        }

        $key = "doc:{$document->id}:due_soon:1:user:{$responsibleUser->id}";
        $trackingNo = $document->tracking_number;
        $formattedDate = $document->due_date ? $document->due_date->format('F j, Y') : '';

        return $this->createIfNotExists([
            'user_id' => $responsibleUser->id,
            'document_id' => $document->id,
            'type' => 'document.due_tomorrow',
            'title' => 'Document due tomorrow',
            'message' => "{$trackingNo} is due tomorrow ({$formattedDate}).",
            'notification_key' => $key,
            'metadata' => [
                'tracking_number' => $trackingNo,
                'days_remaining' => 1,
                'due_date' => $document->due_date?->toDateString(),
            ],
        ]);
    }

    /**
     * Notify responsible user when a document deadline has passed (disabled per notification policy).
     */
    public function notifyOverdue(Document $document, User $responsibleUser): ?Notification
    {
        return null;
    }

    public function notifyDueToday(Document $document, User $responsibleUser): ?Notification
    {
        return null;
    }

    public function markAssignmentNotificationsRead(Document $document, User $user): int
    {
        return Notification::query()
            ->forUser($user->id)
            ->where('document_id', $document->id)
            ->whereIn('type', ['document.received', 'document.force_notify', 'document.due_tomorrow', 'document.due_soon', 'document.completed', 'document.approved'])
            ->unread()
            ->update(['read_at' => now()]);
    }

    /**
     * Helper to safely create notification ensuring uniqueness.
     */
    protected function createIfNotExists(array $attributes): ?Notification
    {
        try {
            return Notification::query()->firstOrCreate(
                ['notification_key' => $attributes['notification_key']],
                $attributes,
            );
        } catch (QueryException $exception) {
            $notification = Notification::query()
                ->where('notification_key', $attributes['notification_key'])
                ->first();

            if ($notification) {
                return $notification;
            }

            throw $exception;
        }
    }
}
