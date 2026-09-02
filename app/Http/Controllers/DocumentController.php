<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentAcknowledgement;
use App\Models\DocumentAttachment;
use App\Models\DocumentHistory;
use App\Models\Office;
use App\Models\ScanPage;
use App\Models\ScanSession;
use App\Models\User;
use App\Services\AuditService;
use App\Services\DocumentAttentionService;
use App\Services\DocumentNotificationService;
use App\Services\DocumentTrackingService;
use App\Services\UserAuditAccess;
use App\Support\RoleNames;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    public function index(Request $request, DocumentAttentionService $attentionService): JsonResponse
    {
        $tab = $request->query('tab', 'my');
        $user = $request->user();
        $query = $request->query('attention') === 'due'
            ? $attentionService->buildAuthorizedDueQuery($user)
            : Document::query();

        $query->with([
            'creator',
            'creator.office',
            'attachments',
            'acknowledgements' => fn ($query) => $query->latest(),
            'acknowledgements.user:id,first_name,last_name,username,is_active,position,office_id',
            'acknowledgements.user.office',
        ]);

        if ($request->query('attention') === 'due') {
            // The canonical authorized due scope replaces tab-specific scopes.
        } elseif ($tab === 'my') {
            $query->forMyDocuments($user);
        } elseif ($tab === 'registry') {
            $query->forRegistry($user);
        } elseif ($tab === 'incoming') {
            $query->forIncoming($user);
        } elseif ($tab === 'outgoing') {
            $query->forOutgoing($user);
        } elseif ($tab === 'archived') {
            $query->where('status', 'archived')
                ->where(function ($q) use ($user) {
                    $q->where('created_by', $user->id)
                        ->orWhere('office_assignment', $user->office?->name)
                        ->orWhereHas('creator', function ($q2) use ($user) {
                            $q2->where('office_id', $user->office_id);
                        });
                });
        } elseif ($tab === 'overdue') {
            $query->forIncoming($user)
                ->where('created_at', '<', now()->subHours(24));
        }

        if ($request->filled('office_assignment')) {
            $assignment = $request->query('office_assignment');
            if ($assignment === '__unassigned__' || $assignment === 'unassigned') {
                $query->where(function ($q) {
                    $q->whereNull('office_id')
                        ->orWhereNull('office_assignment')
                        ->orWhere('office_assignment', '')
                        ->orWhere('office_assignment', 'Unassigned Office');
                });
            } else {
                $query->where('office_assignment', $assignment);
            }
        } elseif ($request->filled('office_id')) {
            $officeId = $request->query('office_id');
            if ($officeId === 'unassigned' || $officeId === '__unassigned__') {
                $query->where(function ($q) {
                    $q->whereNull('office_id')
                        ->orWhereNull('office_assignment')
                        ->orWhere('office_assignment', '')
                        ->orWhere('office_assignment', 'Unassigned Office');
                });
            } else {
                $office = Office::find($officeId);
                if ($office) {
                    $query->where(function ($q) use ($officeId, $office) {
                        $q->where('office_id', $officeId)
                            ->orWhere('office_assignment', $office->name);
                    });
                } else {
                    $query->where('office_id', $officeId);
                }
            }
        }

        if ($request->filled('division_assignment')) {
            $assignment = $request->query('division_assignment');
            if ($assignment === '__unassigned__' || $assignment === 'unassigned') {
                $query->where(function ($q) {
                    $q->whereNull('division_id')
                        ->orWhereNull('division_assignment')
                        ->orWhere('division_assignment', '')
                        ->orWhere('division_assignment', 'Unassigned Division');
                });
            } else {
                $query->where('division_assignment', $assignment);
            }
        } elseif ($request->filled('division_id')) {
            $divisionId = $request->query('division_id');
            if ($divisionId === 'unassigned' || $divisionId === '__unassigned__') {
                $query->where(function ($q) {
                    $q->whereNull('division_id')
                        ->orWhereNull('division_assignment')
                        ->orWhere('division_assignment', '')
                        ->orWhere('division_assignment', 'Unassigned Division');
                });
            } else {
                $division = Division::find($divisionId);
                if ($division) {
                    $query->where(function ($q) use ($divisionId, $division) {
                        $q->where('division_id', $divisionId)
                            ->orWhere('division_assignment', $division->name);
                    });
                } else {
                    $query->where('division_id', $divisionId);
                }
            }
        }

        $documents = $query->latest()->get();
        $assignedUserIds = $documents
            ->pluck('assigned_user')
            ->filter(fn ($assignedUser) => is_string($assignedUser) && Str::isUuid($assignedUser))
            ->unique()
            ->values();
        $assignedUsers = User::query()
            ->whereKey($assignedUserIds)
            ->get()
            ->keyBy('id');

        $documents->each(function ($doc) use ($assignedUsers) {
            $assigned = $doc->assigned_user;
            if (! empty($assigned) && Str::isUuid($assigned)) {
                $user = $assignedUsers->get($assigned);
                $doc->assigned_user = $user ? $user->full_name : $assigned;
            }
        });

        return response()->json([
            'data' => $documents,
        ]);
    }

    public function show(Request $request, Document $document, DocumentTrackingService $trackingService): JsonResponse
    {
        $this->authorizeDocumentRead($request, $document, $trackingService);

        $document->load([
            'creator',
            'creator.office',
            'attachments',
            'acknowledgements' => fn ($query) => $query->latest(),
            'acknowledgements.user:id,first_name,last_name,username,is_active,position,office_id',
            'acknowledgements.user.office',
        ]);

        $assigned = $document->assigned_user;
        if (! empty($assigned) && Str::isUuid($assigned)) {
            $user = User::find($assigned);
            $document->assigned_user = $user ? $user->full_name : $assigned;
        }

        return response()->json([
            'data' => $document,
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
                'user:id,first_name,last_name,username,is_active,office_id',
                'user.office:id,name,short_name',
                'fromUser:id,first_name,last_name,username,is_active,office_id',
                'fromUser.office:id,name,short_name',
                'toUser:id,first_name,last_name,username,is_active,office_id',
                'toUser.office:id,name,short_name',
            ])
            ->oldest('created_at')
            ->orderBy('id')
            ->get();

        $viewerCanSeeConfidential = $viewer->can('documents.view_confidential') || $viewer->can('can_view_confidential');
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
            $user = $request->user();

            if ($validated['send_to_all'] ?? false) {
                $validated['is_internal'] = true;
            }

            if ($validated['is_internal'] ?? false) {
                // Only default origin_sender to the registrar's office if none was explicitly provided.
                // Do NOT overwrite it — the user may have intentionally typed a different origin.
                if (empty($validated['origin_sender'])) {
                    $validated['origin_sender'] = $user->office?->name ?? $user->full_name;
                }
            }

            if ($validated['self_assign'] ?? false) {
                $validated['office_assignment'] = $user->office?->name;
                $validated['division_assignment'] = $user->division?->name;
                $validated['assigned_user'] = $user->full_name;
            }

            $userOffice = $user->office?->name;
            $destOffice = $validated['office_assignment'] ?? null;

            // Similarity check for same office
            $isSameOffice = false;
            if ($userOffice && $destOffice) {
                $userClean = str_ireplace(['office of the city', 'office of the', 'office', 'officer'], '', $userOffice);
                $userClean = trim(preg_replace('/\s+/', ' ', $userClean));

                $destClean = str_ireplace(['office of the city', 'office of the', 'office', 'officer'], '', $destOffice);
                $destClean = trim(preg_replace('/\s+/', ' ', $destClean));

                if (stripos($userClean, $destClean) !== false || stripos($destClean, $userClean) !== false) {
                    $isSameOffice = true;
                }
            }

            $isSelf = $validated['self_assign'] ?? false;
            if ($isSelf) {
                $newStatus = 'registered';
            } elseif ($isSameOffice) {
                $newStatus = 'pending';
            } else {
                $newStatus = 'released';
            }

            // Resolve target user if possible
            $targetUser = null;
            $assignedName = $validated['assigned_user'] ?? null;
            if ($assignedName && $assignedName !== '-Select user-' && $assignedName !== '-Select office first-') {
                // First try resolving by ID (like in release)
                $targetUser = User::find($assignedName);
                if ($targetUser) {
                    $assignedName = $targetUser->full_name;
                } else {
                    // Resolve by full name string
                    $targetUser = $this->resolveAssignedUser($assignedName);
                }
            }

            $document = Document::create([
                ...$validated,
                'tracking_number' => 'DOC-'.Str::upper(Str::random(12)),
                'created_by' => $user->id,
                'assigned_user' => $assignedName,
                'status' => $newStatus,
            ]);

            $disk = config('filesystems.document_disk', 'shared_documents');

            foreach ($request->file('attachments', []) as $file) {
                $path = $file->store("documents/{$document->id}", $disk);

                $document->attachments()->create([
                    'disk' => $disk,
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                    'size' => $file->getSize(),
                ]);
            }

            // Sync phone-scanned pages if a session is provided
            if ($request->filled('scan_session_token')) {
                $scanPages = ScanPage::where('session_token', $request->scan_session_token)
                    ->orderBy('page_number')
                    ->get();

                $defaultDisk = config('filesystems.default', 'local');

                foreach ($scanPages as $page) {
                    if (Storage::disk($defaultDisk)->exists($page->file_path)) {
                        $originalName = basename($page->file_path);
                        $mimeType = Storage::disk($defaultDisk)->mimeType($page->file_path) ?? 'image/png';
                        $size = Storage::disk($defaultDisk)->size($page->file_path);

                        // Copy from temp scanner directory to permanent document disk location
                        $newPath = "documents/{$document->id}/{$originalName}";
                        Storage::disk($disk)->writeStream(
                            $newPath,
                            Storage::disk($defaultDisk)->readStream($page->file_path)
                        );

                        $document->attachments()->create([
                            'disk' => $disk,
                            'path' => $newPath,
                            'original_name' => "scanned_page_{$page->page_number}.png",
                            'mime_type' => $mimeType,
                            'size' => $size,
                            'type' => 'document',
                        ]);
                    }
                }

                // Cleanup temporary storage and session
                Storage::disk($defaultDisk)->deleteDirectory("scan-sessions/{$request->scan_session_token}");
                ScanSession::where('token', $request->scan_session_token)->delete();
            }

            // 1. Log in canonical document transaction history.
            $historyType = ($newStatus === 'released') ? DocumentHistory::RELEASED : DocumentHistory::REGISTERED;
            $historyRemarks = $document->remarks ?: (($newStatus === 'released') ? 'Document physically released.' : 'Document registered.');

            DocumentHistory::record($document, $historyType, $request->user(), [
                'to_user_id' => $targetUser?->id,
                'to_division_id' => $targetUser?->division_id,
                'remarks' => $historyRemarks,
                'from_office' => $document->origin_sender ?? $user->office?->name,
                'to_office' => $document->office_assignment,
                'status_after' => $document->status,
            ]);

            // If sent to all and internal:
            if ($document->send_to_all && $document->is_internal) {
                DocumentHistory::record($document, 'shared', $request->user(), [
                    'remarks' => 'Shared and sent to all employees in '.($user->office?->name ?? 'the office').'.',
                    'from_office' => $user->office?->name,
                    'to_office' => $user->office?->name,
                    'status_before' => $document->status,
                    'status_after' => $document->status,
                ]);
            }

            // 3. Create pending acknowledgement if a user is assigned
            if ($assignedName && $assignedName !== '-Select user-' && $assignedName !== '-Select office first-') {
                $acknowledgement = $document->acknowledgements()->create([
                    'user_id' => $targetUser?->id,
                    'assigned_name' => $assignedName,
                    'from_office' => $document->origin_sender ?? $user->office?->name,
                    'remarks' => ($newStatus === 'released') ? 'Pending acknowledgement (Released).' : 'Pending acknowledgement.',
                ]);

                if ($newStatus !== 'released') {
                    DocumentHistory::record($document, DocumentHistory::ASSIGNED, $request->user(), [
                        'to_user_id' => $targetUser?->id,
                        'to_division_id' => $targetUser?->division_id,
                        // No remark on first assignment — only re-assignment shows a remark
                        'remarks' => null,
                        'from_office' => $document->origin_sender ?? $user->office?->name,
                        'to_office' => $document->office_assignment,
                        'status_before' => $document->status,
                        'status_after' => $document->status,
                        'metadata' => [
                            'assigned_name' => $assignedName,
                        ],
                    ]);
                }

                if ($targetUser) {
                    app(DocumentNotificationService::class)->notifyReceived(
                        $document,
                        $targetUser,
                        $request->user(),
                        $acknowledgement->id,
                    );
                }
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
            'from_office' => $event->from_office,
            'to_office' => $event->to_office,
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

        $officeName = $user?->relationLoaded('office') && $user->office ? ($user->office->name ?? $user->office->short_name) : null;

        return [
            'id' => $user?->id,
            'name' => $name,
            'office' => $officeName,
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

    public function update(Request $request, Document $document): JsonResponse
    {
        $validated = $request->validate([
            'document_type' => 'nullable|string',
            'priority_level' => 'nullable|string',
            'description' => 'nullable|string|max:30',
            'is_confidential' => 'nullable|boolean',
            'remarks' => 'nullable|string',
            'due_date' => 'nullable|date',
        ]);

        $document->update($validated);

        $historyRemarks = 'Document details were updated.';
        if (! empty($validated['remarks'])) {
            $historyRemarks = $validated['remarks'];
        }

        DocumentHistory::record($document, 'edited', $request->user(), [
            'remarks' => $historyRemarks,
        ]);

        return response()->json([
            'message' => 'Document updated successfully.',
            'document' => $document->fresh(),
        ]);
    }

    public function transfer(Request $request, Document $document): JsonResponse
    {
        $validated = $request->validate([
            'office_assignment' => 'nullable|string',
            'division_assignment' => 'nullable|string',
            'assigned_user' => 'nullable|string',
            'remarks' => 'nullable|string',
        ]);

        $statusBefore = $document->status;
        $fromOffice = $document->office_assignment ?? $document->origin_sender;
        $currentUser = $request->user();

        $destOffice = $validated['office_assignment'] ?? $document->office_assignment;
        $destDivision = $validated['division_assignment'] ?? null;
        $assignedName = $validated['assigned_user'] ?? null;

        $targetUser = null;
        if ($assignedName && $assignedName !== '-Select user-' && $assignedName !== '-Select office first-') {
            $targetUser = User::find($assignedName);
            if ($targetUser) {
                $assignedName = $targetUser->full_name;
            } else {
                $targetUser = $this->resolveAssignedUser($assignedName);
            }
        }

        $document->update([
            'office_assignment' => $destOffice,
            'division_assignment' => $destDivision,
            'assigned_user' => $assignedName,
            'status' => 'transferred',
        ]);

        $remarks = ! empty($validated['remarks']) ? $validated['remarks'] : 'Document transferred.';

        DocumentHistory::record($document, DocumentHistory::TRANSFERRED, $currentUser, [
            'to_user_id' => $targetUser?->id,
            'to_division_id' => $targetUser?->division_id,
            'remarks' => $remarks,
            'from_office' => $fromOffice,
            'to_office' => $destOffice,
            'status_before' => $statusBefore,
            'status_after' => 'transferred',
            'metadata' => [
                'assigned_name' => $document->assigned_user,
            ],
        ]);

        return response()->json([
            'message' => 'Document transferred successfully.',
            'document' => $document->fresh([
                'creator',
                'creator.office',
                'attachments',
                'acknowledgements' => fn ($q) => $q->latest(),
            ]),
        ]);
    }

    public function release(Request $request, Document $document): JsonResponse
    {
        $validated = $request->validate([
            'to_office' => 'nullable|string',
            'to_division' => 'nullable|string',
            'to_user' => 'nullable|string',
            'remarks' => 'nullable|string',
        ]);

        $statusBefore = $document->status;

        $targetUser = null;
        $assignedUser = null;
        if (! empty($validated['to_user'])) {
            $targetUser = User::find($validated['to_user']);
            if ($targetUser) {
                $assignedUser = $targetUser->full_name;
            }
        }

        $document->update([
            'status' => 'released',
            'office_assignment' => $validated['to_office'] ?? $document->office_assignment,
            'division_assignment' => $validated['to_division'] ?? null,
            'assigned_user' => $assignedUser ?? $validated['to_user'] ?? null,
        ]);

        if ($targetUser) {
            $acknowledgement = $document->acknowledgements()->create([
                'user_id' => $targetUser->id,
                'assigned_name' => $targetUser->full_name,
                'from_office' => $request->user()->office?->name,
                'remarks' => 'Pending acknowledgement (Released).',
            ]);

            app(DocumentNotificationService::class)->notifyReceived(
                $document,
                $targetUser,
                $request->user(),
                $acknowledgement->id,
            );
        }

        DocumentHistory::record($document, 'released', $request->user(), [
            'to_user_id' => $targetUser?->id,
            'to_division_id' => $targetUser?->division_id,
            'remarks' => $validated['remarks'] ?? 'Document physically released.',
            'to_office' => $validated['to_office'] ?? null,
            'status_before' => $statusBefore,
            'status_after' => 'released',
            'metadata' => [
                'released_to' => $assignedUser ?? $validated['to_user'] ?? null,
            ],
        ]);

        return response()->json([
            'message' => 'Document released successfully.',
            'document' => $document->fresh(),
        ]);
    }

    public function archive(Request $request, Document $document): JsonResponse
    {
        $statusBefore = $document->status;

        $document->update([
            'status' => 'archived',
        ]);

        DocumentHistory::record($document, 'archived', $request->user(), [
            'remarks' => $request->input('remarks') ?: 'Document archived.',
            'status_before' => $statusBefore,
            'status_after' => 'archived',
        ]);

        return response()->json([
            'message' => 'Document archived successfully.',
            'document' => $document->fresh(),
        ]);
    }

    public function returnDocument(Request $request, Document $document): JsonResponse
    {
        $validated = $request->validate([
            'remarks' => 'nullable|string',
        ]);

        $statusBefore = $document->status;
        $fromOffice = $document->office_assignment;

        // Find the last user who assigned, transferred, or created this document before the current user
        $lastHistory = DocumentHistory::where('document_id', $document->id)
            ->whereIn('action', ['ASSIGNED', 'TRANSFERRED', 'FORWARDED', 'RELEASED', 'REGISTERED', 'RETURNED'])
            ->where('performed_by', '!=', $request->user()->id)
            ->latest()
            ->first();

        $lastUser = null;
        if ($lastHistory) {
            $lastUser = User::find($lastHistory->performed_by);
        }
        if (! $lastUser) {
            $lastUser = $document->creator;
        }

        $toOffice = $lastUser?->office?->name ?? $document->origin_sender;

        $document->update([
            'status' => 'returned',
            'office_assignment' => $toOffice,
            'division_assignment' => $lastUser?->division?->name,
            'assigned_user' => $lastUser ? $lastUser->full_name : null,
        ]);

        if ($lastUser) {
            $acknowledgement = $document->acknowledgements()->create([
                'user_id' => $lastUser->id,
                'assigned_name' => $lastUser->full_name,
                'from_office' => $request->user()->office?->name,
                'remarks' => 'Pending acknowledgement (Returned).',
            ]);

            app(DocumentNotificationService::class)->notifyReceived(
                $document,
                $lastUser,
                $request->user(),
                $acknowledgement->id,
            );
        }

        DocumentHistory::record($document, DocumentHistory::RETURNED, $request->user(), [
            'to_user_id' => $lastUser?->id,
            'to_division_id' => $lastUser?->division_id,
            'remarks' => $validated['remarks'] ?? 'Document returned to sender.',
            'from_office' => $fromOffice,
            'to_office' => $toOffice,
            'status_before' => $statusBefore,
            'status_after' => 'returned',
        ]);

        return response()->json([
            'message' => 'Document returned successfully.',
            'document' => $document->fresh(),
        ]);
    }

    public function complete(Request $request, Document $document): JsonResponse
    {
        $validated = $request->validate([
            'remarks' => 'nullable|string',
        ]);

        $statusBefore = $document->status;

        $document->update([
            'status' => 'completed',
            'remarks' => $validated['remarks'] ?? null,
        ]);

        DocumentHistory::record($document, DocumentHistory::COMPLETED, $request->user(), [
            'remarks' => $validated['remarks'] ?? 'Document marked as complete.',
            'status_before' => $statusBefore,
            'status_after' => 'completed',
        ]);

        app(DocumentNotificationService::class)->notifyApproved($document, $request->user());

        return response()->json([
            'message' => 'Document marked as complete.',
            'document' => $document->fresh(),
        ]);
    }

    public function share(Request $request, Document $document): JsonResponse
    {
        $validated = $request->validate([
            'office' => 'required|string',
            'division' => 'nullable|string',
            'user' => 'nullable|string',
            'note' => 'nullable|string',
        ]);

        DocumentHistory::record($document, 'shared', $request->user(), [
            'remarks' => $validated['note'] ?? 'Document shared.',
            'to_office' => $validated['office'],
            'metadata' => [
                'to_division' => $validated['division'] ?? null,
                'shared_with_user' => $validated['user'] ?? null,
            ],
        ]);

        return response()->json([
            'message' => 'Document shared successfully.',
        ]);
    }

    public function showAttachment(
        Request $request,
        DocumentAttachment $attachment,
        DocumentTrackingService $trackingService,
    ) {
        $document = $attachment->document;
        abort_unless($document, 404, 'Document not found.');
        $this->authorizeDocumentRead($request, $document, $trackingService);

        $disk = $attachment->disk ?? 'shared_documents';
        /** @var FilesystemAdapter $storage */
        $storage = Storage::disk($disk);
        if (! $storage->exists($attachment->path)) {
            abort(404, 'File not found');
        }

        return $storage->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
        ], 'inline');
    }

    public function retrieve(Request $request, Document $document): JsonResponse
    {
        $validated = $request->validate([
            'remarks' => 'nullable|string',
        ]);

        $statusBefore = $document->status;

        $document->update([
            'status' => 'registered',
        ]);

        DocumentHistory::record($document, 'RETRIEVED', $request->user(), [
            'remarks' => isset($validated['remarks']) && trim($validated['remarks']) !== '' ? trim($validated['remarks']) : 'Document retrieved from completed.',
            'status_before' => $statusBefore,
            'status_after' => 'registered',
        ]);

        return response()->json([
            'message' => 'Document retrieved successfully.',
            'document' => $document->fresh(),
        ]);
    }

    public function getMessages(Document $document): JsonResponse
    {
        $messages = $document->messages()
            ->with(['user:id,first_name,last_name,username,position,office_id', 'user.office'])
            ->oldest()
            ->get();

        return response()->json([
            'data' => $messages,
        ]);
    }

    public function sendMessage(Request $request, Document $document): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string',
            'type' => 'nullable|string',
        ]);

        $message = $document->messages()->create([
            'user_id' => $request->user()->id,
            'message' => $validated['message'],
            'type' => $validated['type'] ?? 'comment',
        ]);

        return response()->json([
            'message' => 'Message sent successfully.',
            'data' => $message->load(['user:id,first_name,last_name,username,position,office_id', 'user.office']),
        ], 201);
    }

    public function getDocumentTypes(): JsonResponse
    {
        $types = Document::query()
            ->whereNotNull('document_type')
            ->where('document_type', '!=', '')
            ->distinct()
            ->pluck('document_type')
            ->toArray();

        $defaults = ['Memo', 'Letter', 'Report'];
        $combined = array_values(array_unique(array_merge($defaults, $types)));

        return response()->json($combined);
    }

    /**
     * Reassign a document to another user within their office.
     * Allowed for: admins, supervisors, or the current holder of the document.
     */
    public function reassign(Request $request, Document $document): JsonResponse
    {
        $user = $request->user();

        $roleName = strtolower($user->roles->first()?->name ?? '');
        $isAdmin = $user->username === 'admin' || $user->hasAnyRole(RoleNames::adminRoles());
        $isSupervisor = $roleName === 'supervisor';
        $isCurrentHolder = $document->assigned_user === $user->full_name
            || $document->assigned_user === $user->id;

        if (! $isAdmin && ! $isSupervisor && ! $isCurrentHolder) {
            return response()->json(['message' => 'You are not authorized to reassign this document.'], 403);
        }

        $validated = $request->validate([
            'assigned_user' => 'required|string',
            'remarks' => 'nullable|string',
        ]);

        $statusBefore = $document->status;

        // Resolve the target user
        $targetUser = User::find($validated['assigned_user']);
        $assignedName = $validated['assigned_user'];
        if ($targetUser) {
            $assignedName = $targetUser->full_name;
        } else {
            $targetUser = $this->resolveAssignedUser($validated['assigned_user']);
            if ($targetUser) {
                $assignedName = $targetUser->full_name;
            }
        }

        $document->update([
            'assigned_user' => $assignedName,
        ]);

        // Create acknowledgement for the new assignee
        if ($targetUser) {
            $acknowledgement = $document->acknowledgements()->create([
                'user_id' => $targetUser->id,
                'assigned_name' => $assignedName,
                'from_office' => $user->office?->name,
                'remarks' => 'Pending acknowledgement (Reassigned).',
            ]);

            app(DocumentNotificationService::class)->notifyReceived(
                $document,
                $targetUser,
                $user,
                $acknowledgement->id,
            );
        }

        DocumentHistory::record($document, 'REASSIGNED', $user, [
            'to_user_id' => $targetUser?->id,
            'to_division_id' => $targetUser?->division_id,
            'remarks' => $validated['remarks'] ?? "Document reassigned to {$assignedName}.",
            'from_office' => $document->office_assignment,
            'to_office' => $document->office_assignment,
            'status_before' => $statusBefore,
            'status_after' => $document->status,
            'metadata' => [
                'assigned_name' => $assignedName,
            ],
        ]);

        return response()->json([
            'message' => 'Document reassigned successfully.',
            'document' => $document->fresh()->load('attachments'),
        ]);
    }

    /**
     * Admin/supervisor-only: force-send a notification to the assigned user.
     */
    public function forceNotify(Request $request, Document $document): JsonResponse
    {
        $user = $request->user();

        $assignedName = $document->assigned_user;
        if (empty($assignedName)) {
            return response()->json(['message' => 'Document has no assigned user.'], 422);
        }

        $targetUser = $this->resolveAssignedUser($assignedName);
        if (! $targetUser) {
            return response()->json(['message' => 'Could not resolve the assigned user.'], 422);
        }

        // Delete any existing notifications for this document and user first, so that the unique keys are cleared and a new notification is generated.
        \App\Models\Notification::where('document_id', $document->id)
            ->where('user_id', $targetUser->id)
            ->delete();

        app(DocumentNotificationService::class)->notifyReceived($document, $targetUser, $user);
        app(DocumentNotificationService::class)->notifyPending($document, $targetUser);

        DocumentHistory::record($document, 'FORCE_NOTIFIED', $user, [
            'to_user_id' => $targetUser->id,
            'remarks' => $request->input('remarks') ?? 'Force notification sent by '.$user->full_name.'.',
            'status_before' => $document->status,
            'status_after' => $document->status,
        ]);

        return response()->json([
            'message' => 'Notification sent to '.$assignedName.'.',
        ]);
    }

    /**
     * Get users in the requesting user's office (for admin reassign dropdown).
     */
    public function officeUsers(Request $request): JsonResponse
    {
        $user = $request->user();
        $officeId = $user->office_id;

        if (! $officeId) {
            return response()->json([]);
        }

        $users = User::where('office_id', $officeId)
            ->where('is_active', true)
            ->select('id', 'first_name', 'last_name', 'username', 'position', 'office_id', 'division_id')
            ->orderBy('first_name')
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->full_name,
                'position' => $u->position,
                'division_id' => $u->division_id,
            ]);

        return response()->json($users);
    }

    public function addAttachment(Request $request, Document $document): JsonResponse
    {
        $request->validate([
            'file' => 'required|file',
            'type' => 'nullable|string|max:50',
        ]);

        $file = $request->file('file');
        $disk = config('filesystems.document_disk', 'shared_documents');
        $path = $file->store("documents/{$document->id}", $disk);

        $attachment = $document->attachments()->create([
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => $file->getSize(),
            'type' => $request->input('type', 'attachment'),
        ]);

        DocumentHistory::record($document, DocumentHistory::EDITED, $request->user(), [
            'remarks' => "Added attachment: {$file->getClientOriginalName()}",
        ]);

        return response()->json([
            'message' => 'Attachment added successfully.',
            'attachment' => $attachment,
            'document' => $document->load('attachments'),
        ]);
    }

    public function syncScanSession(Request $request, Document $document): JsonResponse
    {
        $request->validate([
            'scan_session_token' => 'required|string',
        ]);

        $scanPages = ScanPage::where('session_token', $request->scan_session_token)
            ->orderBy('page_number')
            ->get();

        if ($scanPages->isEmpty()) {
            return response()->json([
                'message' => 'No scanned pages found for this session.',
            ], 400);
        }

        $disk = config('filesystems.document_disk', 'shared_documents');
        $defaultDisk = config('filesystems.default', 'local');
        $addedCount = 0;

        foreach ($scanPages as $page) {
            if (Storage::disk($defaultDisk)->exists($page->file_path)) {
                $originalName = basename($page->file_path);
                $mimeType = Storage::disk($defaultDisk)->mimeType($page->file_path) ?? 'image/png';
                $size = Storage::disk($defaultDisk)->size($page->file_path);

                // Copy from temp scanner directory to permanent document disk location
                $newPath = "documents/{$document->id}/{$originalName}";
                Storage::disk($disk)->writeStream(
                    $newPath,
                    Storage::disk($defaultDisk)->readStream($page->file_path)
                );

                $document->attachments()->create([
                    'disk' => $disk,
                    'path' => $newPath,
                    'original_name' => "scanned_page_{$page->page_number}.png",
                    'mime_type' => $mimeType,
                    'size' => $size,
                    'type' => 'scanned_photo',
                ]);
                $addedCount++;
            }
        }

        if ($addedCount > 0) {
            DocumentHistory::record($document, DocumentHistory::EDITED, $request->user(), [
                'remarks' => "Added {$addedCount} phone-scanned pages.",
            ]);
        }

        return response()->json([
            'message' => "Successfully synced {$addedCount} scanned pages.",
            'document' => $document->load('attachments'),
        ]);
    }

    public function deleteAttachment(Request $request, DocumentAttachment $attachment): JsonResponse
    {
        $document = $attachment->document;
        $disk = $attachment->disk ?? 'shared_documents';
        if (Storage::disk($disk)->exists($attachment->path)) {
            Storage::disk($disk)->delete($attachment->path);
        }
        $filename = $attachment->original_name;
        $attachment->delete();

        if ($document) {
            DocumentHistory::record($document, DocumentHistory::EDITED, $request->user(), [
                'remarks' => "Deleted attachment: {$filename}",
            ]);
        }

        return response()->json([
            'message' => 'Attachment deleted successfully.',
            'document' => $document ? $document->load('attachments') : null,
        ]);
    }

    public function unopenedCount(Request $request): JsonResponse
    {
        $user = $request->user();

        $count = DocumentAcknowledgement::query()
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhere('assigned_name', $user->full_name);
            })
            ->whereNull('acknowledged_at')
            ->count();

        return response()->json([
            'count' => $count,
        ]);
    }

    public function acknowledge(
        Request $request,
        Document $document,
        DocumentTrackingService $trackingService,
        DocumentNotificationService $notificationService,
    ): JsonResponse {
        $this->authorizeDocumentRead($request, $document, $trackingService);

        $user = $request->user();

        /** @var DocumentAcknowledgement|null $acknowledgement */
        $acknowledgement = $document->acknowledgements()
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhere('assigned_name', $user->full_name);
            })
            ->whereNull('acknowledged_at')
            ->first();

        if ($acknowledgement) {
            $acknowledgement->update([
                'acknowledged_at' => now(),
                'remarks' => 'Acknowledged/Opened by recipient.',
            ]);

            $statusBefore = $document->status;
            $newStatus = $document->status;
            if (in_array(strtolower($document->status), ['released', 'outgoing'])) {
                $newStatus = 'pending';
            }

            $document->update([
                'status' => $newStatus,
            ]);
        }

        $notificationService->markAssignmentNotificationsRead($document, $user);

        return response()->json([
            'message' => 'Document acknowledged successfully.',
            'document' => $document->fresh(),
            'unread_notifications' => $user->notifications()->whereNull('read_at')->count(),
        ]);
    }

    private function authorizeDocumentRead(
        Request $request,
        Document $document,
        DocumentTrackingService $trackingService,
    ): void {
        $isAuthorized = $trackingService
            ->buildAuthorizedDocumentQuery($request->user())
            ->whereKey($document->getKey())
            ->exists();

        abort_unless($isAuthorized, 403, 'You are not authorized to view this document.');
    }

    public function receive(Request $request, Document $document): JsonResponse
    {
        $user = $request->user();
        $userOffice = $user->office?->name ?? 'HR Division';
        $userDivision = $user->division?->name;
        $statusBefore = $document->status;
        $fromOffice = $document->office_assignment ?? $document->origin_sender;

        $document->update([
            'office_assignment' => $userOffice,
            'division_assignment' => $userDivision,
            'assigned_user' => $user->full_name,
            'status' => 'pending',
        ]);

        $document->acknowledgements()->create([
            'user_id' => $user->id,
            'assigned_name' => $user->full_name,
            'from_office' => $fromOffice,
            'acknowledged_at' => now(),
            'remarks' => $request->input('remarks') ?: 'Document received.',
        ]);

        DocumentHistory::record($document, DocumentHistory::RECEIVED, $user, [
            'to_user_id' => $user->id,
            'to_division_id' => $user->division_id,
            'from_office' => $fromOffice,
            'to_office' => $userOffice,
            'remarks' => $request->input('remarks') ?: 'Document received.',
            'status_before' => $statusBefore,
            'status_after' => 'pending',
            'metadata' => [
                'assigned_name' => $user->full_name,
            ],
        ]);

        return response()->json([
            'message' => 'Document received successfully.',
            'document' => $document->fresh([
                'creator',
                'creator.office',
                'attachments',
                'acknowledgements' => fn ($q) => $q->latest(),
            ]),
        ]);
    }

    public function receiveByCode(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string',
            'remarks' => 'nullable|string',
        ]);

        $rawCode = trim((string) $request->input('code'));

        $trackingNumber = $rawCode;
        if (preg_match('/(?:track[\/=]|document-tracking\/|documents\/)(DOC-[A-Z0-9_-]+)/i', $rawCode, $matches)) {
            $trackingNumber = $matches[1];
        } elseif (preg_match('/(DOC-[A-Z0-9_-]+)/i', $rawCode, $matches)) {
            $trackingNumber = $matches[1];
        }

        $document = Document::whereRaw('LOWER(tracking_number) = ?', [strtolower($trackingNumber)])
            ->orWhere('id', $trackingNumber)
            ->first();

        if (! $document) {
            return response()->json([
                'message' => "No document found matching tracking number '{$trackingNumber}'.",
            ], 404);
        }

        return $this->receive($request, $document);
    }

    public function getByTracking(Request $request, string $trackingNumber): JsonResponse
    {
        $cleaned = trim($trackingNumber);
        $document = Document::whereRaw('LOWER(tracking_number) = ?', [strtolower($cleaned)])
            ->orWhere('id', $cleaned)
            ->with([
                'creator',
                'creator.office',
                'attachments',
                'acknowledgements' => fn ($q) => $q->latest(),
            ])
            ->first();

        if (! $document) {
            return response()->json([
                'message' => 'Document not found.',
            ], 404);
        }

        return response()->json([
            'data' => $document,
        ]);
    }

    public function publicTrack(Request $request, string $trackingNumber): JsonResponse
    {
        $cleaned = trim($trackingNumber);
        $document = Document::whereRaw('LOWER(tracking_number) = ?', [strtolower($cleaned)])
            ->orWhere('id', $cleaned)
            ->with([
                'creator',
                'creator.office',
                'attachments',
                'histories' => fn ($q) => $q->with(['user.office', 'user.division', 'toUser'])->latest(),
            ])
            ->first();

        if (! $document) {
            return response()->json([
                'message' => 'Document not found.',
            ], 404);
        }

        $timeline = $document->histories->map(function ($h) {
            $action = strtoupper($h->action);
            $performerName = $h->user ? $h->user->full_name : 'System';
            $performerOffice = $h->user?->office?->name ?? $h->from_office ?? '';
            $performer = $performerOffice ? "By {$performerName} - {$performerOffice}" : "By {$performerName}";

            $title = match ($action) {
                'REGISTERED' => 'Document registered',
                'TRANSFERRED' => "Document transferred",
                'RECEIVED' => 'Document received',
                'EDITED' => 'Document edited',
                'COMPLETED' => 'Document completed',
                'RELEASED' => 'Document released',
                'RETURNED' => 'Document returned',
                'ASSIGNED' => "Document assigned to " . ($h->toUser?->full_name ?? $h->metadata['assigned_name'] ?? 'Staff'),
                default => 'Document ' . strtolower($action),
            };

            $route = null;
            if ($h->from_office && $h->to_office && $h->from_office !== $h->to_office) {
                $route = "{$h->from_office} → {$h->to_office}";
            }

            return [
                'id' => $h->id,
                'action' => $action,
                'title' => $title,
                'performer' => $performer,
                'route' => $route,
                'remarks' => $h->remarks,
                'timestamp' => $h->created_at ? $h->created_at->format('M d, Y, h:i A') : '',
            ];
        });

        return response()->json([
            'document' => [
                'id' => $document->id,
                'tracking_number' => $document->tracking_number,
                'particular' => $document->description,
                'document_type' => $document->document_type,
                'priority' => $document->priority_level,
                'origin' => $document->origin_sender ?? $document->office_assignment ?? 'City HR Office',
                'contact_person' => $document->sender_name ?? $document->creator?->full_name ?? 'HR Officer',
                'contact_number' => $document->creator?->phone ?? $document->creator?->contact_number ?? '0956-477-897',
                'status' => $document->status,
                'due_date' => $document->due_date?->format('M d, Y'),
                'created_at' => $document->created_at?->format('M d, Y'),
            ],
            'timeline' => $timeline,
        ]);
    }
}
