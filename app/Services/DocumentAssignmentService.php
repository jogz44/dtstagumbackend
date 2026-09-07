<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentAssignment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DocumentAssignmentService
{
    public function assign(
        Document $document,
        User $assignedUser,
        ?User $assignedBy = null,
        ?Carbon $assignedAt = null,
        ?string $notes = null,
    ): DocumentAssignment {
        return DB::transaction(function () use ($document, $assignedUser, $assignedBy, $assignedAt, $notes): DocumentAssignment {
            $timestamp = $assignedAt ?? now();

            $document->assignments()
                ->where('is_current', true)
                ->lockForUpdate()
                ->get()
                ->each(function (DocumentAssignment $assignment) use ($timestamp): void {
                    $assignment->update([
                        'is_current' => false,
                        'unassigned_at' => $timestamp,
                    ]);
                });

            $document->update(['assigned_user_id' => $assignedUser->id]);

            return $document->assignments()->create([
                'assigned_user_id' => $assignedUser->id,
                'assigned_by_user_id' => $assignedBy?->id,
                'assigned_at' => $timestamp,
                'is_current' => true,
                'notes' => $notes,
            ]);
        });
    }
}
