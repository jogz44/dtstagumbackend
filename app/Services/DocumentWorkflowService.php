<?php

namespace App\Services;

use App\Models\Document;
use App\Support\DocumentStatuses;
use Illuminate\Support\Carbon;

class DocumentWorkflowService
{
    /**
     * Transition document status and set immutable analytics timestamps once.
     */
    public function transition(Document $document, string $newStatus, array $extraAttributes = []): Document
    {
        $normalizedStatus = strtolower(trim($newStatus));

        // Set received_at on first transition to a received/active reception state
        $receivedStatuses = ['received', 'pending', 'in_process', 'in progress', 'incoming'];
        if (in_array($normalizedStatus, $receivedStatuses, true) && is_null($document->received_at)) {
            $document->received_at = Carbon::now();
        }

        // Set completed_at on first transition to a terminal completion state
        $completedStatuses = ['completed', 'released', 'approved'];
        if (in_array($normalizedStatus, $completedStatuses, true) && is_null($document->completed_at)) {
            $document->completed_at = Carbon::now();
        }

        $attributes = array_merge($extraAttributes, [
            'status' => $newStatus,
        ]);

        if (! is_null($document->received_at)) {
            $attributes['received_at'] = $document->received_at;
        }

        if (! is_null($document->completed_at)) {
            $attributes['completed_at'] = $document->completed_at;
        }

        $document->update($attributes);

        return $document;
    }

    /**
     * Set explicit received_at timestamp (e.g. on registration).
     */
    public function markAsReceived(Document $document, ?Carbon $timestamp = null): Document
    {
        if (is_null($document->received_at)) {
            $document->received_at = $timestamp ?? Carbon::now();
            $document->save();
        }

        return $document;
    }
}
