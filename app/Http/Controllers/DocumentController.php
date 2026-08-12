<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Models\Document;
use App\Models\DocumentHistory;
use App\Models\User;
use App\Services\AuditService;
use App\Services\UserAuditAccess;
use App\Support\RoleNames;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $documents = Document::query()
            ->latest()
            ->get()
            ->map(function ($doc) {
                $assigned = $doc->assigned_user;
                if (!empty($assigned) && Str::isUuid($assigned)) {
                    $user = \App\Models\User::find($assigned);
                    $doc->assigned_user = $user ? $user->full_name : $assigned;
                }
                return $doc;
            });

        return response()->json([
            'data' => $documents
        ]);
    }

    public function timeline(Request $request, Document $document, UserAuditAccess $access): JsonResponse
    {
        $viewer = $request->user();

        $this->authorizeTimelineRead($viewer, $document, $access);

        $document->load([
            'acknowledgements' => fn ($query) => $query->latest(),
            'acknowledgements.user:id,first_name,last_name,username,is_active',
        ]);

        $events = DocumentHistory::query()
            ->where('document_id', $document->id)
            ->with([
                'user:id,first_name,last_name,username,is_active',
                'fromUser:id,first_name,last_name,username,is_active',
                'toUser:id,first_name,last_name,username,is_active',
            ])
            ->oldest('created_at')
            ->orderBy('id')
            ->get();

        $viewerCanSeeConfidential = $viewer->can('documents.view_confidential');
        $canShowSensitiveFields = ! $document->is_confidential || $viewerCanSeeConfidential;

        return response()->json([
            'document' => [
                'id' => $document->id,
                'tracking_number' => $document->tracking_number,
                'particular' => $canShowSensitiveFields ? $document->description : null,
                'type' => $document->document_type,
                'priority' => $document->priority_level,
                'status' => $document->status,
                'current_holder' => $canShowSensitiveFields ? $this->currentHolderPayload($document) : null,
                'is_confidential' => $document->is_confidential,
                'is_redacted' => ! $canShowSensitiveFields,
            ],
            'data' => $events
                ->map(fn (DocumentHistory $event) => $this->timelineEventPayload($event, $canShowSensitiveFields))
                ->values(),
        ]);
    }

    public function store(StoreDocumentRequest $request, AuditService $audit): JsonResponse
    {
        $validated = $request->safe()->except('attachments');

        $document = DB::transaction(function () use ($request, $validated) {
            $document = Document::create([
                ...$validated,
                'tracking_number' => 'DOC-'.Str::upper(Str::random(12)),
                'created_by' => $request->user()->id,
                // Receive/uploaded documents are immediately visible to their creator as outgoing.
                'status' => 'outgoing',
            ]);

            foreach ($request->file('attachments', []) as $file) {
                $path = $file->store("documents/{$document->id}", 'local');

                $document->attachments()->create([
                    'disk' => 'local',
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                    'size' => $file->getSize(),
                ]);
            }

            // 1. Log in canonical document transaction history.
            DocumentHistory::record($document, DocumentHistory::REGISTERED, $request->user(), [
                'remarks' => 'Document registered in the system.',
                'from_office' => $document->origin_sender,
                'to_office' => $document->office_assignment,
                'status_after' => $document->status,
            ]);

            // 2. Add message/remarks if present
            if (!empty($document->remarks)) {
                $document->messages()->create([
                    'user_id' => $request->user()->id,
                    'message' => $document->remarks,
                    'type' => 'remark',
                ]);
            }

            // 3. Create pending acknowledgement if a user is assigned
            if (!empty($document->assigned_user) && $document->assigned_user !== '-Select user-' && $document->assigned_user !== '-Select office first-') {
                $assignedName = $document->assigned_user;
                $localUser = $this->resolveAssignedUser($assignedName);

                $document->acknowledgements()->create([
                    'user_id' => $localUser?->id,
                    'assigned_name' => $assignedName,
                    'remarks' => 'Pending acknowledgement.',
                ]);

                DocumentHistory::record($document, DocumentHistory::ASSIGNED, $request->user(), [
                    'to_user_id' => $localUser?->id,
                    'to_division_id' => $localUser?->division_id,
                    'remarks' => "Document assigned to {$assignedName}.",
                    'from_office' => $document->origin_sender,
                    'to_office' => $document->office_assignment,
                    'status_before' => $document->status,
                    'status_after' => $document->status,
                    'metadata' => [
                        'assigned_name' => $assignedName,
                    ],
                ]);
            }

            return $document;
        });

        $audit->activity(
            $request->user(),
            'document.received',
            'Documents',
            'Uploaded a new document.',
            $request,
            [
                'resource_type' => 'document',
                'resource_id' => $document->id,
            ],
        );

        return response()->json([
            'message' => 'Document saved successfully.',
            'document' => $document->load('attachments'),
        ], 201);
    }

    private function authorizeTimelineRead(User $authUser, Document $document, UserAuditAccess $access): void
    {
        if ($authUser->hasRole(RoleNames::ADMIN)) {
            return;
        }

        $isInvolved = DocumentHistory::query()
            ->where('document_id', $document->id)
            ->involvingUser($authUser)
            ->exists();

        if ($isInvolved) {
            return;
        }

        $involvedUserIds = DocumentHistory::query()
            ->where('document_id', $document->id)
            ->get(['performed_by', 'from_user_id', 'to_user_id'])
            ->flatMap(fn (DocumentHistory $history) => [
                $history->performed_by,
                $history->from_user_id,
                $history->to_user_id,
            ])
            ->filter()
            ->unique()
            ->values();

        $canAuditAnInvolvedUser = User::query()
            ->whereIn('id', $involvedUserIds)
            ->get(['id', 'office_id', 'division_id'])
            ->contains(fn (User $targetUser) => $access->canRead($authUser, $targetUser));

        if ($canAuditAnInvolvedUser) {
            return;
        }

        abort(403, 'You are not authorized to view this document timeline.');
    }

    private function timelineEventPayload(DocumentHistory $event, bool $canShowSensitiveFields): array
    {
        $metadata = $event->metadata ?? [];

        return [
            'event_id' => $event->id,
            'action' => $event->action,
            'actor' => $canShowSensitiveFields ? $this->userPayload($event->user) : null,
            'from_user' => $canShowSensitiveFields ? $this->userPayload($event->fromUser, $metadata['from_user_name'] ?? null) : null,
            'to_user' => $canShowSensitiveFields ? $this->userPayload($event->toUser, $metadata['assigned_name'] ?? $metadata['to_user_name'] ?? null) : null,
            'status_before' => $event->status_before,
            'status_after' => $event->status_after,
            'remarks' => $canShowSensitiveFields ? $event->remarks : null,
            'performed_at' => $event->created_at?->toIso8601String(),
        ];
    }

    private function userPayload(?User $user, ?string $fallbackName = null): ?array
    {
        $name = $user?->full_name ?: $user?->username ?: $fallbackName;

        if (! $name) {
            return null;
        }

        return [
            'id' => $user?->id,
            'name' => $name,
            'is_active' => $user?->is_active,
        ];
    }

    private function currentHolderPayload(Document $document): ?array
    {
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
            'is_active' => $holderUser?->is_active,
        ];
    }

    private function resolveAssignedUser(string $assignedName): ?User
    {
        $normalizedAssignedName = $this->normalizeName($assignedName);
        $terms = collect(preg_split('/\s+/', $assignedName, -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn (string $term) => strlen($term) > 1)
            ->values();

        $candidates = User::query()
            ->when($terms->isNotEmpty(), function ($query) use ($terms) {
                $query->where(function ($nameQuery) use ($terms) {
                    foreach ($terms as $term) {
                        $nameQuery
                            ->orWhere('first_name', 'LIKE', "%{$term}%")
                            ->orWhere('last_name', 'LIKE', "%{$term}%");
                    }
                });
            })
            ->limit(25)
            ->get();

        return $candidates->first(
            fn (User $user) => $this->normalizeName($user->full_name) === $normalizedAssignedName
        ) ?? $candidates->first();
    }

    private function normalizeName(string $name): string
    {
        return str($name)
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->lower()
            ->toString();
    }
}
