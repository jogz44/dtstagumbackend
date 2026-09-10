<?php

namespace App\Services;

use App\Models\DocumentAcknowledgement;
use App\Models\Notification;
use App\Models\User;
use App\Support\DocumentStatuses;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DocumentAttentionService
{
    public function __construct(private DocumentTrackingService $trackingService) {}

    public function buildAuthorizedQuery(User $user): Builder
    {
        return $this->trackingService->buildAuthorizedDocumentQuery($user);
    }

    public function buildAuthorizedDueQuery(User $user): Builder
    {
        $query = $this->buildAuthorizedQuery($user);

        return $this->applyDueScope($query, $user);
    }

    public function applyDueScope(Builder $query, User $user): Builder
    {
        return $this->applyPendingScope($query, $user)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', today());
    }

    public function applyPendingScope(Builder $query, User $user): Builder
    {
        return $query->whereNotIn(DB::raw('LOWER(status)'), DocumentStatuses::TERMINAL);
    }

    public function getDueCount(User $user): int
    {
        return $this->buildAuthorizedDueQuery($user)->count();
    }

    public function getPendingCount(User $user): int
    {
        $query = $this->buildAuthorizedQuery($user);

        return $this->applyPendingScope($query, $user)->count();
    }

    /**
     * @return array{due_documents: int, pending_documents: int, unopened_assignments: int, unread_notifications: int}
     */
    public function getAttentionSummary(User $user): array
    {
        $unopenedAssignments = DocumentAcknowledgement::query()
            ->where(function (Builder $query) use ($user) {
                $query->where('user_id', $user->id)
                    ->orWhere('assigned_name', $user->full_name);
            })
            ->whereNull('acknowledged_at')
            ->whereHas('document', function (Builder $qDoc) use ($user) {
                $qDoc->where(function ($qC) use ($user) {
                    $qC->where('created_by', '!=', $user->id)
                       ->orWhere('assigned_user', $user->full_name)
                       ->orWhere('assigned_user', (string) $user->id);
                })
                ->whereNotIn(DB::raw('LOWER(status)'), DocumentStatuses::TERMINAL);
            })
            ->count();

        return [
            'due_documents' => $this->getDueCount($user),
            'pending_documents' => $this->getPendingCount($user),
            'unopened_assignments' => $unopenedAssignments,
            'unread_notifications' => Notification::query()
                ->forUser($user->id)
                ->unread()
                ->count(),
        ];
    }
}
