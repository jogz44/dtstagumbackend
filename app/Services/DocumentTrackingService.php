<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentHistory;
use App\Models\User;
use App\Support\DocumentStatuses;
use App\Support\RoleNames;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DocumentTrackingService
{
    /**
     * Build the complete tracking summary for a given document.
     */
    public function getTrackingSummary(Document $document, User $viewer): array
    {
        $viewerCanSeeConfidential = $this->checkIsAdmin($viewer)
            || $viewer->can('documents.view_confidential')
            || $viewer->can('can_view_confidential');

        $canShowSensitiveFields = ! $document->is_confidential || $viewerCanSeeConfidential;

        $document->loadMissing([
            'creator' => fn ($q) => $q->withTrashed()->with(['office', 'division']),
            'acknowledgements' => fn ($q) => $q->latest(),
            'acknowledgements.user' => fn ($q) => $q->withTrashed()->with(['office', 'division', 'pmisEmployee']),
        ]);

        $currentHolder = $canShowSensitiveFields ? $this->resolveCurrentHolder($document) : null;
        $currentLocation = $this->resolveCurrentLocation($document, $currentHolder);
        $stage = $this->resolveNormalizedStage($document);
        $deadline = $this->calculateDeadline($document);
        $timeline = $this->buildTimeline($document, $canShowSensitiveFields);

        $lastMovementAt = $this->getLastMovementTimestamp($document);
        $receivedAt = $this->getReceivedTimestamp($document);

        $timeAtCurrentLocation = $this->calculateTimeAtCurrentLocation($document, $lastMovementAt);
        $totalProcessingTime = $this->calculateTotalProcessingTime($document);

        return [
            'document' => [
                'id' => $document->id,
                'document_number' => $document->tracking_number,
                'subject' => $canShowSensitiveFields ? $document->description : 'Confidential Document',
                'document_type' => $document->document_type ?? 'N/A',
                'priority' => $document->priority_level ?? 'normal',
                'sender' => $document->origin_sender ?? $document->sender_name ?? 'N/A',
                'due_date' => $document->due_date?->format('Y-m-d'),
                'registered_at' => $document->created_at?->toIso8601String(),
                'is_confidential' => (bool) $document->is_confidential,
                'is_redacted' => ! $canShowSensitiveFields,
            ],
            'tracking' => [
                'stage' => $stage,
                'workflow_status' => strtolower((string) ($document->status ?? 'registered')),
                'current_holder' => $currentHolder,
                'current_location' => $currentLocation,
                'received_at' => $receivedAt?->toIso8601String(),
                'last_movement_at' => $lastMovementAt?->toIso8601String(),
                'time_at_current_location' => $timeAtCurrentLocation,
                'total_processing_time' => $totalProcessingTime,
                'deadline' => $deadline,
            ],
            'timeline' => $timeline,
        ];
    }

    /**
     * Get paginated tracking records for overview listing.
     */
    public function getPaginatedTrackingList(User $viewer, array $filters = []): array
    {
        $query = $this->buildAuthorizedDocumentQuery($viewer);

        $scope = strtolower((string) ($filters['scope'] ?? 'all'));
        $status = strtolower((string) ($filters['status'] ?? 'all'));
        $search = trim((string) ($filters['search'] ?? ''));

        // Apply Scope filter
        if ($scope === 'incoming') {
            $this->applyIncomingCondition($query, $viewer);
        } elseif ($scope === 'outgoing') {
            $this->applyOutgoingCondition($query, $viewer);
        }

        // Apply Status filter (case-insensitive across workflow statuses)
        if ($status === 'pending') {
            $query->whereIn(DB::raw('LOWER(status)'), ['registered', 'outgoing', 'incoming', 'pending', 'in progress', 'in_transit']);
        } elseif ($status === 'completed') {
            $query->whereIn(DB::raw('LOWER(status)'), ['completed', 'released', 'approved']);
        } elseif ($status === 'overdue') {
            $query->whereNotIn(DB::raw('LOWER(status)'), DocumentStatuses::TERMINAL)
                ->whereNotNull('due_date')
                ->where('due_date', '<=', Carbon::today()->format('Y-m-d'));
        } elseif ($status === 'received') {
            $query->where(function ($q) {
                $q->whereIn(DB::raw('LOWER(status)'), ['incoming', 'received'])
                    ->orWhereHas('histories', fn ($qH) => $qH->whereIn(DB::raw('UPPER(action)'), ['RECEIVED', 'ACKNOWLEDGED']));
            });
        } elseif ($status === 'in_process') {
            $query->whereIn(DB::raw('LOWER(status)'), ['incoming', 'pending', 'outgoing', 'in progress', 'in_transit']);
        }

        // Apply Search query
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('tracking_number', 'LIKE', "%{$search}%")
                    ->orWhere('description', 'LIKE', "%{$search}%")
                    ->orWhere('origin_sender', 'LIKE', "%{$search}%")
                    ->orWhere('sender_name', 'LIKE', "%{$search}%")
                    ->orWhere('office_assignment', 'LIKE', "%{$search}%")
                    ->orWhere('assigned_user', 'LIKE', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $filters['sort_by'] ?? 'last_movement';
        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        if ($sortBy === 'created_at') {
            $query->orderBy('created_at', $sortOrder);
        } elseif ($sortBy === 'due_date') {
            $query->orderBy('due_date', $sortOrder);
        } elseif ($sortBy === 'tracking_number') {
            $query->orderBy('tracking_number', $sortOrder);
        } elseif ($sortBy === 'status') {
            $query->orderBy('status', $sortOrder);
        } else {
            // Default sort by last movement / updated_at
            $query->orderBy('updated_at', $sortOrder);
        }

        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 25)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $viewerCanSeeConfidential = $this->checkIsAdmin($viewer)
            || $viewer->can('documents.view_confidential')
            || $viewer->can('can_view_confidential');

        $items = collect($paginator->items())->map(function (Document $doc) use ($viewer, $viewerCanSeeConfidential) {
            $canShow = ! $doc->is_confidential || $viewerCanSeeConfidential || $doc->created_by === $viewer->id || $doc->assigned_user === $viewer->full_name || $doc->assigned_user === $viewer->id;

            $doc->loadMissing([
                'acknowledgements' => fn ($q) => $q->latest(),
                'acknowledgements.user' => fn ($q) => $q->withTrashed()->with(['office', 'division', 'pmisEmployee']),
            ]);

            $currentHolder = $canShow ? $this->resolveCurrentHolder($doc) : null;
            $currentLocation = $this->resolveCurrentLocation($doc, $currentHolder);
            $stage = $this->resolveNormalizedStage($doc);
            $deadline = $this->calculateDeadline($doc);
            $direction = $this->resolveDirectionForUser($doc, $viewer);
            $lastMovementAt = $this->getLastMovementTimestamp($doc);

            return [
                'id' => $doc->id,
                'document_number' => $doc->tracking_number,
                'subject' => $canShow ? ($doc->description ?? 'No subject') : 'Confidential Document',
                'document_type' => $doc->document_type ?? 'N/A',
                'priority' => $doc->priority_level ?? 'normal',
                'direction' => $direction,
                'current_location' => $currentLocation,
                'current_holder' => $currentHolder,
                'stage' => $stage,
                'workflow_status' => strtolower((string) ($doc->status ?? 'registered')),
                'deadline' => $deadline,
                'last_movement_at' => $lastMovementAt?->toIso8601String(),
                'is_confidential' => (bool) $doc->is_confidential,
            ];
        });

        return [
            'data' => $items->toArray(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    /**
     * Get statistics counts (Total, Incoming, Outgoing, Pending, Overdue, Completed)
     * scoped to the authenticated user's authorized dataset.
     */
    public function getTrackingStatistics(User $viewer): array
    {
        $baseQuery = $this->buildAuthorizedDocumentQuery($viewer);

        $total = (clone $baseQuery)->count();

        $incomingQuery = clone $baseQuery;
        $this->applyIncomingCondition($incomingQuery, $viewer);
        $incomingCount = $incomingQuery->count();

        $outgoingQuery = clone $baseQuery;
        $this->applyOutgoingCondition($outgoingQuery, $viewer);
        $outgoingCount = $outgoingQuery->count();

        $pendingCount = (clone $baseQuery)
            ->whereIn(DB::raw('LOWER(status)'), ['registered', 'outgoing', 'incoming', 'pending', 'in progress', 'in_transit'])
            ->count();

        $overdueCount = (clone $baseQuery)
            ->whereNotIn(DB::raw('LOWER(status)'), DocumentStatuses::TERMINAL)
            ->whereNotNull('due_date')
            ->where('due_date', '<=', Carbon::today()->format('Y-m-d'))
            ->count();

        $completedCount = (clone $baseQuery)
            ->whereIn(DB::raw('LOWER(status)'), ['completed', 'released', 'approved'])
            ->count();

        return [
            'total' => $total,
            'incoming' => $incomingCount,
            'outgoing' => $outgoingCount,
            'pending' => $pendingCount,
            'overdue' => $overdueCount,
            'completed' => $completedCount,
        ];
    }

    /**
     * Base authorized query returning unique documents accessible to $viewer.
     */
    public function buildAuthorizedDocumentQuery(User $viewer): Builder
    {
        $query = Document::query()->where(DB::raw('LOWER(status)'), '!=', 'archived');

        if ($this->checkIsAdmin($viewer)) {
            return $query;
        }

        $viewer->loadMissing('roles');
        $roleName = strtolower($viewer->roles->first()?->name ?? '');
        $isSupervisor = $roleName === 'supervisor';

        if ($isSupervisor) {
            $officeName = $viewer->office?->name;
            $officeNames = Document::getSimilarOfficeNames($officeName);

            return $query->where(function ($q) use ($viewer, $officeNames) {
                if ($viewer->office_id) {
                    $q->whereHas('creator', fn ($q2) => $q2->where('office_id', $viewer->office_id));
                }
                if (!empty($officeNames)) {
                    $q->orWhereIn('office_assignment', $officeNames)
                      ->orWhere('origin_sender', $viewer->office?->name);
                }
                $q->orWhere('created_by', $viewer->id)
                  ->orWhere('assigned_user', $viewer->full_name)
                  ->orWhere('assigned_user', (string) $viewer->id);
            });
        }

        return $query->where(function ($q) use ($viewer) {
            $q->forMyDocuments($viewer)
              ->orWhere(fn ($sub) => $sub->forOutgoing($viewer));
        });
    }

    public function checkIsAdmin(User $user): bool
    {
        if ($user->hasRole(RoleNames::ADMIN)) {
            return true;
        }

        $user->loadMissing('roles');
        $roleName = strtolower($user->roles->first()?->name ?? '');

        return in_array($roleName, RoleNames::adminRoles(), true);
    }

    public function applyIncomingCondition(Builder $query, User $viewer): void
    {
        $viewer->loadMissing('roles');
        $roleName = strtolower($viewer->roles->first()?->name ?? '');
        $isElevatedUser = $this->checkIsAdmin($viewer) || $roleName === 'supervisor';

        if ($isElevatedUser) {
            $query->whereIn(DB::raw('LOWER(status)'), ['registered', 'incoming', 'pending', 'received', 'in_process', 'in progress', 'in_transit']);
            return;
        }

        $query->forIncoming($viewer);
    }

    public function applyOutgoingCondition(Builder $query, User $viewer): void
    {
        $viewer->loadMissing('roles');
        $roleName = strtolower($viewer->roles->first()?->name ?? '');
        $isElevatedUser = $this->checkIsAdmin($viewer) || $roleName === 'supervisor';

        if ($isElevatedUser) {
            $query->whereIn(DB::raw('LOWER(status)'), ['outgoing', 'released', 'transferred', 'forwarded']);
            return;
        }

        $query->forOutgoing($viewer);
    }

    public function resolveDirectionForUser(Document $document, User $viewer): string
    {
        $officeName = $viewer->office?->name;

        // Check if assigned/routed to viewer or viewer's office
        $isAssignedToViewer = ($document->assigned_user === $viewer->full_name || $document->assigned_user === $viewer->id)
            || ($officeName && $document->office_assignment === $officeName)
            || $document->acknowledgements->contains(fn ($a) => $a->user_id === $viewer->id || $a->assigned_name === $viewer->full_name);

        // Check if created or dispatched by viewer
        $isSentByViewer = ($document->created_by === $viewer->id)
            || ($document->origin_sender && $officeName && $document->origin_sender === $officeName);

        if ($isSentByViewer && ! $isAssignedToViewer) {
            return 'outgoing';
        }

        if ($isAssignedToViewer && ! $isSentByViewer) {
            return 'incoming';
        }

        if ($isSentByViewer && $isAssignedToViewer) {
            return strtolower((string) $document->status) === 'outgoing' ? 'outgoing' : 'incoming';
        }

        return strtolower((string) $document->status) === 'outgoing' ? 'outgoing' : 'incoming';
    }

    /**
     * Resolve the current holder using resolution order:
     * 1. Current acknowledgement user UUID / user model
     * 2. Document assigned_user UUID or string
     * 3. Fallback resolveAssignedUser() matching
     */
    public function resolveCurrentHolder(Document $document): ?array
    {
        $latestAck = $document->acknowledgements->first();
        $holderUser = $latestAck?->user;

        // P1: Check acknowledgement user
        if ($holderUser) {
            return $this->formatUserPayload($holderUser);
        }

        $assignedUserField = $document->assigned_user;

        if (empty($assignedUserField) || in_array($assignedUserField, ['-Select user-', '-Select office first-'], true)) {
            return null;
        }

        // P2: Assigned user field is UUID
        if (Str::isUuid($assignedUserField)) {
            $userByUuid = User::withTrashed()->with(['office', 'division', 'pmisEmployee'])->find($assignedUserField);
            if ($userByUuid) {
                return $this->formatUserPayload($userByUuid);
            }
        }

        // P3: Fallback resolve user by name
        $resolvedUser = $this->resolveAssignedUserByName($assignedUserField);
        if ($resolvedUser) {
            return $this->formatUserPayload($resolvedUser);
        }

        // P4: Return free-text name with document assignment
        return [
            'id' => null,
            'name' => $latestAck?->assigned_name ?? $assignedUserField,
            'employee_id' => null,
            'position' => null,
            'office' => $document->office_assignment,
            'division' => $document->division_assignment,
            'is_active' => true,
        ];
    }

    /**
     * Resolve current location (Office, Division).
     */
    public function resolveCurrentLocation(Document $document, ?array $currentHolder = null): array
    {
        $office = $document->office_assignment;
        $division = $document->division_assignment;

        if (! $office && $currentHolder) {
            $office = $currentHolder['office'] ?? null;
        }

        if (! $division && $currentHolder) {
            $division = $currentHolder['division'] ?? null;
        }

        if (! $office) {
            $latestHistoryWithOffice = DocumentHistory::query()
                ->where('document_id', $document->id)
                ->whereNotNull('to_office')
                ->latest('created_at')
                ->first();

            $office = $latestHistoryWithOffice?->to_office ?? $document->origin_sender ?? 'N/A';
        }

        return [
            'office' => $office ?? 'N/A',
            'division' => $division ?? 'N/A',
        ];
    }

    /**
     * Map internal document state & actions to top 5 normalized display stages:
     * 1. registered
     * 2. routed
     * 3. received
     * 4. in_process
     * 5. completed
     */
    public function resolveNormalizedStage(Document $document): string
    {
        $status = strtolower((string) $document->status);

        if (in_array($status, ['completed', 'released', 'archived', 'approved'], true)) {
            return 'completed';
        }

        $latestAction = DocumentHistory::query()
            ->where('document_id', $document->id)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->value('action');

        $action = strtoupper((string) $latestAction);

        if ($action === 'COMPLETED' || $action === 'RELEASED' || $action === 'APPROVED') {
            return 'completed';
        }

        if ($action === 'RECEIVED' || $action === 'ACKNOWLEDGED') {
            return 'received';
        }

        if (in_array($action, ['ASSIGNED', 'TRANSFERRED', 'FORWARDED', 'REASSIGNED'], true) || $status === 'outgoing') {
            return 'routed';
        }

        if ($status === 'returned') {
            return 'routed';
        }

        if ($action === 'REGISTERED') {
            return 'registered';
        }

        if ($document->office_assignment || $document->assigned_user) {
            $hasAck = $document->acknowledgements()
                ->whereNotNull('acknowledged_at')
                ->exists();

            return $hasAck ? 'in_process' : 'routed';
        }

        return 'registered';
    }

    /**
     * Calculate deadline state (is_due_today, is_due_soon, is_overdue, days_remaining, days_overdue).
     */
    public function calculateDeadline(Document $document): ?array
    {
        if (! $document->due_date) {
            return null;
        }

        $today = Carbon::today();
        $dueDate = Carbon::parse($document->due_date)->startOfDay();

        $isCompleted = in_array(strtolower((string) $document->status), ['completed', 'released', 'archived', 'approved'], true);

        $diffDays = (int) $today->diffInDays($dueDate, false);

        $isDueToday = ($diffDays === 0);
        $isOverdue = (! $isCompleted && $diffDays < 0);
        $isDueSoon = (! $isCompleted && $diffDays > 0 && $diffDays <= 2);

        return [
            'due_date' => $dueDate->format('Y-m-d'),
            'is_due_today' => $isDueToday,
            'is_due_soon' => $isDueSoon,
            'is_overdue' => $isOverdue,
            'days_remaining' => max(0, $diffDays),
            'days_overdue' => $isOverdue ? abs($diffDays) : 0,
        ];
    }

    /**
     * Build timeline of history events, ordered newest-first (descending).
     */
    public function buildTimeline(Document $document, bool $canShowSensitiveFields): array
    {
        $events = DocumentHistory::query()
            ->where('document_id', $document->id)
            ->with([
                'user' => fn ($q) => $q->withTrashed()->with(['office', 'division']),
                'fromUser' => fn ($q) => $q->withTrashed()->with(['office', 'division']),
                'toUser' => fn ($q) => $q->withTrashed()->with(['office', 'division']),
            ])
            ->latest('created_at')
            ->orderBy('id', 'desc')
            ->get();

        return $events->map(function (DocumentHistory $event) use ($canShowSensitiveFields) {
            $normalizedAction = strtoupper($event->action);

            return [
                'id' => $event->id,
                'type' => strtolower("document.{$normalizedAction}"),
                'action' => $normalizedAction,
                'label' => $this->getHumanLabelForAction($normalizedAction),
                'occurred_at' => $event->created_at?->toIso8601String(),
                'actor' => $canShowSensitiveFields ? $this->formatUserPayload($event->user) : null,
                'from_user' => $canShowSensitiveFields ? $this->formatUserPayload($event->fromUser, $event->metadata['from_user_name'] ?? null) : null,
                'to_user' => $canShowSensitiveFields ? $this->formatUserPayload($event->toUser, $event->metadata['assigned_name'] ?? $event->metadata['to_user_name'] ?? null) : null,
                'from_office' => $event->from_office,
                'to_office' => $event->to_office,
                'status_before' => $event->status_before,
                'status_after' => $event->status_after,
                'remarks' => $canShowSensitiveFields ? $this->filterAutoRemarks($event->remarks) : null,
            ];
        })->values()->toArray();
    }

    private function filterAutoRemarks(?string $remarks): ?string
    {
        if ($remarks === null) {
            return null;
        }
        $trimmed = trim($remarks);
        if ($trimmed === '') {
            return null;
        }
        $autoRemarks = [
            'document marked as complete.',
            'document received.',
            'document physically received via qr scan.',
            'document physically released.',
            'document registered.',
            'pending acknowledgement.',
            'pending acknowledgement (released).',
            'pending acknowledgement (transferred).',
            'pending acknowledgement (returned).',
            'pending acknowledgement (reassigned).',
            'document transferred.',
            'document returned to sender.',
            'document archived.',
            'document retrieved from completed.',
            'document shared.',
            'acknowledged/opened by recipient.',
        ];
        if (in_array(strtolower($trimmed), $autoRemarks) || str_starts_with(strtolower($trimmed), 'document reassigned to')) {
            return null;
        }
        return $remarks;
    }

    private function getHumanLabelForAction(string $action): string
    {
        return match (strtoupper($action)) {
            'REGISTERED' => 'Registered',
            'ROUTED' => 'Routed',
            'FORWARDED' => 'Forwarded',
            'TRANSFERRED', 'ASSIGNED' => 'Assigned / Forwarded',
            'RECEIVED' => 'Received',
            'ACKNOWLEDGED' => 'Acknowledged',
            'RETURNED' => 'Returned',
            'REASSIGNED' => 'Reassigned',
            'COMPLETED', 'APPROVED' => 'Completed',
            'RELEASED' => 'Released',
            'SHARED' => 'Shared',
            'RETRIEVED' => 'Retrieved',
            'EDITED' => 'Updated',
            default => ucfirst(strtolower($action)),
        };
    }

    private function formatUserPayload(?User $user, ?string $fallbackName = null): ?array
    {
        if (! $user && ! $fallbackName) {
            return null;
        }

        $name = $user?->full_name ?: $user?->username ?: $fallbackName ?: 'System / Unknown';
        $office = $user?->office?->name ?? $user?->office?->short_name;
        $division = $user?->division?->name ?? $user?->division?->short_name;

        return [
            'id' => $user?->id,
            'name' => $name,
            'employee_id' => $user?->employee_id ?? $user?->pmisEmployee?->emp_id,
            'position' => $user?->position ?? $user?->pmisEmployee?->position,
            'office' => $office,
            'division' => $division,
            'is_active' => $user ? (bool) $user->is_active : true,
        ];
    }

    private function resolveAssignedUserByName(string $assignedName): ?User
    {
        $normalized = str($assignedName)->replaceMatches('/\s+/', ' ')->trim()->lower()->toString();

        $terms = collect(preg_split('/\s+/', $assignedName, -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn (string $term) => strlen($term) > 1)
            ->values();

        $candidates = User::withTrashed()
            ->with(['office', 'division', 'pmisEmployee'])
            ->when($terms->isNotEmpty(), function ($query) use ($terms) {
                $query->where(function ($q) use ($terms) {
                    foreach ($terms as $term) {
                        $q->orWhere('first_name', 'LIKE', "%{$term}%")
                            ->orWhere('last_name', 'LIKE', "%{$term}%");
                    }
                });
            })
            ->limit(25)
            ->get();

        return $candidates->first(
            fn (User $u) => str($u->full_name)->replaceMatches('/\s+/', ' ')->trim()->lower()->toString() === $normalized
        ) ?? $candidates->first();
    }

    private function getLastMovementTimestamp(Document $document): ?Carbon
    {
        $latestHistory = DocumentHistory::query()
            ->where('document_id', $document->id)
            ->latest('created_at')
            ->first();

        return $latestHistory?->created_at ?? $document->created_at;
    }

    private function getReceivedTimestamp(Document $document): ?Carbon
    {
        $ack = $document->acknowledgements()
            ->whereNotNull('acknowledged_at')
            ->latest('acknowledged_at')
            ->first();

        if ($ack?->acknowledged_at) {
            return $ack->acknowledged_at;
        }

        $receivedHistory = DocumentHistory::query()
            ->where('document_id', $document->id)
            ->whereIn('action', ['RECEIVED', 'ACKNOWLEDGED'])
            ->latest('created_at')
            ->first();

        return $receivedHistory?->created_at;
    }

    private function calculateTimeAtCurrentLocation(Document $document, ?Carbon $lastMovementAt): ?string
    {
        if (in_array(strtolower((string) $document->status), ['completed', 'released', 'archived', 'approved'], true)) {
            return null;
        }

        if (! $lastMovementAt) {
            return null;
        }

        $diff = $lastMovementAt->diff(now());
        $parts = [];

        if ($diff->d > 0) {
            $parts[] = "{$diff->d} day".($diff->d > 1 ? 's' : '');
        }

        if ($diff->h > 0) {
            $parts[] = "{$diff->h} hour".($diff->h > 1 ? 's' : '');
        }

        if (empty($parts)) {
            return "{$diff->i} min".($diff->i > 1 ? 's' : '');
        }

        return implode(' ', $parts);
    }

    private function calculateTotalProcessingTime(Document $document): ?string
    {
        $start = $document->created_at;

        if (! $start) {
            return null;
        }

        $end = in_array(strtolower((string) $document->status), ['completed', 'released', 'archived', 'approved'], true)
            ? ($this->getLastMovementTimestamp($document) ?? now())
            : now();

        $diff = $start->diff($end);
        $parts = [];

        if ($diff->d > 0) {
            $parts[] = "{$diff->d} day".($diff->d > 1 ? 's' : '');
        }

        if ($diff->h > 0) {
            $parts[] = "{$diff->h} hour".($diff->h > 1 ? 's' : '');
        }

        if (empty($parts)) {
            return "{$diff->i} min".($diff->i > 1 ? 's' : '');
        }

        return implode(' ', $parts);
    }
}
