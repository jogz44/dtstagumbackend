<?php

namespace App\Http\Controllers;

use App\Models\AccountHistoryEvent;
use App\Models\ActivityEvent;
use App\Models\Document;
use App\Models\DocumentHistory;
use App\Models\User;
use App\Services\UserAuditAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserAuditController extends Controller
{
    public function history(Request $request, User $user, UserAuditAccess $access): JsonResponse
    {
        $access->authorize($request->user(), $user);

        $events = AccountHistoryEvent::query()
            ->with('actorUser:id,first_name,last_name,username')
            ->where('subject_user_id', $user->id)
            ->when($request->filled('event_type'), fn ($query) => $query->where('event_type', $request->string('event_type')))
            ->latest('occurred_at')
            ->paginate($request->integer('per_page', 10));

        return response()->json([
            'data' => collect($events->items())->map(fn (AccountHistoryEvent $event) => [
                'id' => $event->id,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'changed_by' => $event->actorUser?->full_name ?: $event->actorUser?->username ?: 'System',
                'event_type' => $event->event_type,
                'summary' => $event->summary,
                'changes' => $event->changes ?? [],
            ])->values(),
            'meta' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
            ],
        ]);
    }

    public function activity(Request $request, User $user, UserAuditAccess $access): JsonResponse
    {
        $access->authorize($request->user(), $user);

        $events = ActivityEvent::query()
            ->where('actor_user_id', $user->id)
            ->when($request->filled('activity_type'), fn ($query) => $query->where('activity_type', $request->string('activity_type')))
            ->when($request->filled('module'), fn ($query) => $query->where('module', $request->string('module')))
            ->latest('occurred_at')
            ->paginate($request->integer('per_page', 10));

        return response()->json([
            'data' => collect($events->items())->map(fn (ActivityEvent $event) => [
                'id' => $event->id,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'activity_type' => $event->activity_type,
                'activity' => $event->description,
                'module' => $event->module,
                'resource_type' => $event->resource_type,
                'resource_id' => $event->resource_id,
                'outcome' => $event->outcome,
                'ip_address' => $event->ip_address,
                'device' => $this->deviceLabel($event->user_agent),
                'location' => 'Tagum City, PH',
            ])->values(),
            'meta' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
            ],
        ]);
    }

    public function transactionHistory(Request $request, User $user, UserAuditAccess $access): JsonResponse
    {
        $access->authorize($request->user(), $user);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'action' => ['nullable', 'string', Rule::in(DocumentHistory::EVENT_TYPES)],
            'type' => ['nullable', 'string', 'max:100'],
            'priority' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'page_size' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $pageSize = $this->clampedPageSize((int) ($validated['page_size'] ?? $validated['per_page'] ?? 25));
        $viewerCanSeeConfidential = $request->user()->can('documents.view_confidential');

        $events = DocumentHistory::query()
            ->involvingUser($user)
            ->with([
                'document.acknowledgements' => fn ($query) => $query->latest(),
                'document.acknowledgements.user:id,first_name,last_name,username,is_active',
            ])
            ->when(isset($validated['action']), fn ($query) => $query->where('action', strtoupper($validated['action'])))
            ->when(isset($validated['date_from']), fn ($query) => $query->whereDate('created_at', '>=', $validated['date_from']))
            ->when(isset($validated['date_to']), fn ($query) => $query->whereDate('created_at', '<=', $validated['date_to']))
            ->when(
                isset($validated['type'])
                || isset($validated['priority'])
                || isset($validated['status'])
                || isset($validated['search']),
                function ($query) use ($validated) {
                    $query->whereHas('document', function ($documentQuery) use ($validated) {
                        $documentQuery
                            ->when(isset($validated['type']), fn ($innerQuery) => $innerQuery->where('document_type', $validated['type']))
                            ->when(isset($validated['priority']), fn ($innerQuery) => $innerQuery->where('priority_level', $validated['priority']))
                            ->when(isset($validated['status']), fn ($innerQuery) => $innerQuery->where('status', $validated['status']))
                            ->when(isset($validated['search']), function ($innerQuery) use ($validated) {
                                $search = $validated['search'];

                                $innerQuery->where(function ($searchQuery) use ($search) {
                                    $searchQuery
                                        ->where('tracking_number', 'LIKE', "%{$search}%")
                                        ->orWhere('description', 'LIKE', "%{$search}%");
                                });
                            });
                    });
                }
            )
            ->latest('created_at')
            ->paginate($pageSize);

        return response()->json([
            'data' => collect($events->items())
                ->map(fn (DocumentHistory $event) => $this->transactionHistoryPayload($event, $viewerCanSeeConfidential))
                ->values(),
            'pagination' => [
                'current_page' => $events->currentPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
                'last_page' => $events->lastPage(),
            ],
        ]);
    }

    private function transactionHistoryPayload(DocumentHistory $event, bool $viewerCanSeeConfidential): array
    {
        $document = $event->document;
        $canShowSensitiveDocumentFields = ! $document?->is_confidential || $viewerCanSeeConfidential;

        return [
            'event_id' => $event->id,
            'document_id' => $document?->id ?? $event->document_id,
            'tracking_number' => $event->tracking_number ?? $document?->tracking_number,
            'particular' => $canShowSensitiveDocumentFields ? $document?->description : null,
            'type' => $document?->document_type,
            'priority' => $document?->priority_level,
            'action' => $event->action,
            'current_holder' => $canShowSensitiveDocumentFields ? $this->currentHolderPayload($document) : null,
            'status' => $document?->status,
            'performed_at' => $event->created_at?->toIso8601String(),
            'remarks' => $canShowSensitiveDocumentFields ? $event->remarks : null,
        ];
    }

    private function currentHolderPayload(?Document $document): ?array
    {
        if (! $document) {
            return null;
        }

        $acknowledgement = $document->acknowledgements->first();
        $holderUser = $acknowledgement?->user;
        $holderName = $holderUser?->full_name
            ?: $acknowledgement?->assigned_name
            ?: $document->assigned_user;

        if (! $holderName) {
            return null;
        }

        return [
            'id' => $holderUser?->id,
            'name' => $holderName,
        ];
    }

    private function clampedPageSize(int $requestedPageSize): int
    {
        return match (true) {
            $requestedPageSize <= 25 => 25,
            $requestedPageSize <= 50 => 50,
            default => 100,
        };
    }

    private function deviceLabel(?string $userAgent): string
    {
        if (! $userAgent) {
            return 'Unknown device';
        }

        $browser = str_contains($userAgent, 'Chrome') ? 'Chrome' : (str_contains($userAgent, 'Firefox') ? 'Firefox' : 'Browser');
        $os = str_contains($userAgent, 'Windows') ? 'Windows' : (str_contains($userAgent, 'Mac') ? 'macOS' : 'Device');

        return "{$browser} on {$os}";
    }
}
