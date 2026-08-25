<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Notification;
use App\Models\User;

class DocumentNotificationService
{
    /**
     * Canonical document statuses where actions/deadlines are no longer required.
     */
    public const NON_ACTIONABLE_STATUSES = [
        'completed',
        'archived',
        'released',
    ];

    /**
     * Notify user when a document is assigned or routed to them.
     */
    public function notifyReceived(Document $document, User $receiver, ?User $sender = null): ?Notification
    {
        $key = "doc:{$document->id}:received:user:{$receiver->id}";

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
            ],
        ]);
    }

    /**
     * Notify document originator/creator when a document is completed/approved.
     */
    public function notifyApproved(Document $document, User $actor): ?Notification
    {
        $creator = $document->creator;

        if (! $creator) {
            return null;
        }

        // Do not notify creator if they completed/approved it themselves
        if ($creator->id === $actor->id) {
            return null;
        }

        $key = "doc:{$document->id}:approved:user:{$creator->id}";
        $actorName = $actor->full_name ?: 'Recipient';
        $trackingNo = $document->tracking_number;

        return $this->createIfNotExists([
            'user_id' => $creator->id,
            'document_id' => $document->id,
            'type' => 'document.approved',
            'title' => 'Document approved',
            'message' => "{$trackingNo} was approved and marked complete by {$actorName}.",
            'notification_key' => $key,
            'metadata' => [
                'tracking_number' => $trackingNo,
                'approved_by_id' => $actor->id,
                'approved_by_name' => $actorName,
            ],
        ]);
    }

    /**
     * Notify current responsible user that a document requires their attention/action.
     */
    public function notifyPending(Document $document, User $responsibleUser): ?Notification
    {
        $status = strtolower((string) $document->status);
        if (in_array($status, self::NON_ACTIONABLE_STATUSES, true)) {
            $isAssigned = $document->assigned_user === $responsibleUser->full_name ||
                          $document->assigned_user === $responsibleUser->id ||
                          $document->assigned_user === $responsibleUser->username;
            if (! ($status === 'released' && $isAssigned)) {
                return null;
            }
        }

        $key = "doc:{$document->id}:pending:user:{$responsibleUser->id}";
        $trackingNo = $document->tracking_number;

        return $this->createIfNotExists([
            'user_id' => $responsibleUser->id,
            'document_id' => $document->id,
            'type' => 'document.pending',
            'title' => 'Document needs attention',
            'message' => "{$trackingNo} requires your action.",
            'notification_key' => $key,
            'metadata' => [
                'tracking_number' => $trackingNo,
                'status' => $document->status,
            ],
        ]);
    }

    /**
     * Notify responsible user when a document deadline is approaching.
     */
    public function notifyDueSoon(Document $document, User $responsibleUser, int $days): ?Notification
    {
        if (in_array(strtolower((string) $document->status), self::NON_ACTIONABLE_STATUSES, true)) {
            return null;
        }

        $key = "doc:{$document->id}:due_soon:{$days}:user:{$responsibleUser->id}";
        $trackingNo = $document->tracking_number;
        $formattedDate = $document->due_date ? $document->due_date->format('F j, Y') : '';

        $title = $days === 1 ? 'Document due tomorrow' : "Document due in {$days} days";
        $message = $days === 1
            ? "{$trackingNo} requires your attention before {$formattedDate}."
            : "{$trackingNo} is due on {$formattedDate}.";

        return $this->createIfNotExists([
            'user_id' => $responsibleUser->id,
            'document_id' => $document->id,
            'type' => 'document.due_soon',
            'title' => $title,
            'message' => $message,
            'notification_key' => $key,
            'metadata' => [
                'tracking_number' => $trackingNo,
                'days_remaining' => $days,
                'due_date' => $document->due_date?->toDateString(),
            ],
        ]);
    }

    /**
     * Notify responsible user when a document deadline has passed.
     */
    public function notifyOverdue(Document $document, User $responsibleUser): ?Notification
    {
        if (in_array(strtolower((string) $document->status), self::NON_ACTIONABLE_STATUSES, true)) {
            return null;
        }

        $key = "doc:{$document->id}:overdue:user:{$responsibleUser->id}";
        $trackingNo = $document->tracking_number;
        $formattedDate = $document->due_date ? $document->due_date->format('F j, Y') : 'its due date';

        return $this->createIfNotExists([
            'user_id' => $responsibleUser->id,
            'document_id' => $document->id,
            'type' => 'document.overdue',
            'title' => 'Document overdue',
            'message' => "{$trackingNo} passed its due date on {$formattedDate} and requires attention.",
            'notification_key' => $key,
            'metadata' => [
                'tracking_number' => $trackingNo,
                'due_date' => $document->due_date?->toDateString(),
            ],
        ]);
    }

    /**
     * Helper to safely create notification ensuring uniqueness.
     */
    protected function createIfNotExists(array $attributes): ?Notification
    {
        $existing = Notification::where('notification_key', $attributes['notification_key'])->first();
        if ($existing) {
            return $existing;
        }

        try {
            return Notification::create($attributes);
        } catch (\Throwable $e) {
            // In case of race condition hit unique constraint
            return Notification::where('notification_key', $attributes['notification_key'])->first();
        }
    }
}
