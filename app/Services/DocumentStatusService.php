<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentStatusHistory;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DocumentStatusService
{
    private const STATUSES = [
        'received', 'assigned', 'processing', 'completed',
        'overdue', 'returned', 'rejected', 'revised',
    ];

    public function transition(
        Document $document,
        string $status,
        ?User $changedBy = null,
        ?string $reason = null,
        ?array $metadata = null,
        ?Carbon $changedAt = null,
    ): DocumentStatusHistory {
        if ($status === 'completed') {
            throw new InvalidArgumentException('Use complete() to complete a document.');
        }

        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unsupported document status: '.$status);
        }

        return DB::transaction(function () use ($document, $status, $changedBy, $reason, $metadata, $changedAt): DocumentStatusHistory {
            $document->refresh();
            $history = $document->statusHistory()->create([
                'from_status' => $document->status,
                'to_status' => $status,
                'changed_by_user_id' => $changedBy?->id,
                'changed_at' => $changedAt ?? now(),
                'reason' => $reason,
                'metadata' => $metadata,
            ]);

            $document->update(['status' => $status]);

            return $history;
        });
    }

    public function complete(
        Document $document,
        User $completedBy,
        ?Carbon $completedAt = null,
        ?string $reason = null,
        ?array $metadata = null,
    ): DocumentStatusHistory {
        $timestamp = $completedAt ?? now();

        return DB::transaction(function () use ($document, $completedBy, $timestamp, $reason, $metadata): DocumentStatusHistory {
            $document->refresh();
            $history = $document->statusHistory()->create([
                'from_status' => $document->status,
                'to_status' => 'completed',
                'changed_by_user_id' => $completedBy->id,
                'changed_at' => $timestamp,
                'reason' => $reason,
                'metadata' => $metadata,
            ]);

            $document->update([
                'status' => 'completed',
                'completed_at' => $timestamp,
                'completed_by_user_id' => $completedBy->id,
            ]);

            return $history;
        });
    }

    public function isCurrentlyOverdue(Document $document, ?Carbon $at = null): bool
    {
        return $document->due_at !== null
            && $document->completed_at === null
            && $document->due_at->isBefore($at ?? now());
    }
}
