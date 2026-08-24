<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Models\Document;
use App\Models\DocumentHistory;
use App\Models\User;
use App\Services\AuditService;
use App\Services\DocumentNotificationService;
use App\Services\UserAuditAccess;
use App\Support\RoleNames;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tab = $request->query('tab', 'my');
        $query = Document::query()->with([
            'creator',
            'creator.office',
            'attachments',
            'acknowledgements' => fn ($query) => $query->latest(),
            'acknowledgements.user:id,first_name,last_name,username,is_active,position,office_id',
            'acknowledgements.user.office',
        ]);

        $user = $request->user();

        if ($tab === 'my') {
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

        $documents = $query->latest()->get()->map(function ($doc) {
            $assigned = $doc->assigned_user;
            if (!empty($assigned) && Str::isUuid($assigned)) {
                $user = User::find($assigned);
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
                $validated['origin_sender'] = $user->office?->name ?? $user->full_name;
            }

            if ($validated['self_assign'] ?? false) {
                $validated['office_assignment'] = $user->office?->name;
                $validated['division_assignment'] = $user->division?->name;
                $validated['assigned_user'] = $user->full_name;
            }

            $document = Document::create([
                ...$validated,
                'tracking_number' => 'DOC-'.Str::upper(Str::random(12)),
                'created_by' => $user->id,
                // Automatically set status to registered if self-assigned, else outgoing
                'status' => ($validated['self_assign'] ?? false) ? 'registered' : 'outgoing',
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

            // 1. Log in canonical document transaction history.
            $historyType = DocumentHistory::REGISTERED;
            $historyRemarks = $document->remarks ?: 'Document registered.';

            DocumentHistory::record($document, $historyType, $request->user(), [
                'remarks' => $historyRemarks,
                'from_office' => $document->origin_sender,
                'to_office' => $document->office_assignment,
                'status_after' => $document->status,
            ]);

            // If sent to all and internal:
            if ($document->send_to_all && $document->is_internal) {
                DocumentHistory::record($document, 'shared', $request->user(), [
                    'remarks' => 'Shared and sent to all employees in ' . ($user->office?->name ?? 'the office') . '.',
                    'from_office' => $user->office?->name,
                    'to_office' => $user->office?->name,
                    'status_before' => $document->status,
                    'status_after' => $document->status,
                ]);
            }

            // 2. Add message/remarks if present - REMOVED: remarks are remarks, not messages.

            // 3. Create pending acknowledgement if a user is assigned
            if (!empty($document->assigned_user) && $document->assigned_user !== '-Select user-' && $document->assigned_user !== '-Select office first-') {
                $assignedName = $document->assigned_user;
                $localUser = $this->resolveAssignedUser($assignedName);

                $document->acknowledgements()->create([
                    'user_id' => $localUser?->id,
                    'assigned_name' => $assignedName,
                    'from_office' => $document->origin_sender ?? $user->office?->name,
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

                if ($localUser) {
                    app(DocumentNotificationService::class)->notifyReceived($document, $localUser, $request->user());
                    app(DocumentNotificationService::class)->notifyPending($document, $localUser);
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
        if (!empty($validated['remarks'])) {
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

        $document->update([
            'office_assignment' => $validated['office_assignment'] ?? $document->office_assignment,
            'division_assignment' => $validated['division_assignment'] ?? null,
            'assigned_user' => $validated['assigned_user'] ?? null,
            'status' => 'outgoing', // Set to outgoing for the sender
        ]);

        if (!empty($document->assigned_user) && $document->assigned_user !== '-Select user-' && $document->assigned_user !== '-Select office first-') {
            $assignedName = $document->assigned_user;
            $localUser = $this->resolveAssignedUser($assignedName);

            $document->acknowledgements()->create([
                'user_id' => $localUser?->id,
                'assigned_name' => $assignedName,
                'from_office' => $request->user()->office?->name,
                'remarks' => 'Pending acknowledgement.',
            ]);

            if ($localUser) {
                app(DocumentNotificationService::class)->notifyReceived($document, $localUser, $request->user());
                app(DocumentNotificationService::class)->notifyPending($document, $localUser);
            }
        }

        DocumentHistory::record($document, DocumentHistory::ASSIGNED, $request->user(), [
            'remarks' => $validated['remarks'] ?? 'Document transferred.',
            'from_office' => $fromOffice,
            'to_office' => $document->office_assignment,
            'status_before' => $statusBefore,
            'status_after' => 'outgoing',
            'metadata' => [
                'assigned_name' => $document->assigned_user,
            ],
        ]);

        return response()->json([
            'message' => 'Document transferred successfully.',
            'document' => $document->fresh(),
        ]);
    }

    public function release(Request $request, Document $document): JsonResponse
    {
        $validated = $request->validate([
            'to_office' => 'nullable|string',
            'to_user' => 'nullable|string',
            'remarks' => 'nullable|string',
        ]);

        $statusBefore = $document->status;

        $document->update([
            'status' => 'released',
        ]);

        DocumentHistory::record($document, 'released', $request->user(), [
            'remarks' => $validated['remarks'] ?? 'Document physically released.',
            'to_office' => $validated['to_office'] ?? null,
            'status_before' => $statusBefore,
            'status_after' => 'released',
            'metadata' => [
                'released_to' => $validated['to_user'] ?? null,
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
            ->whereIn('action', ['ASSIGNED', 'TRANSFERRED', 'FORWARDED', 'RELEASED', 'REGISTERED'])
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
            $document->acknowledgements()->create([
                'user_id' => $lastUser->id,
                'assigned_name' => $lastUser->full_name,
                'from_office' => $request->user()->office?->name,
                'remarks' => 'Pending acknowledgement (Returned).',
            ]);

            app(DocumentNotificationService::class)->notifyReceived($document, $lastUser, $request->user());
            app(DocumentNotificationService::class)->notifyPending($document, $lastUser);
        }

        DocumentHistory::record($document, DocumentHistory::RETURNED, $request->user(), [
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

    public function showAttachment(Request $request, \App\Models\DocumentAttachment $attachment)
    {
        $disk = $attachment->disk ?? 'shared_documents';
        /** @var \Illuminate\Filesystem\FilesystemAdapter $storage */
        $storage = Storage::disk($disk);
        if (!$storage->exists($attachment->path)) {
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
            'data' => $messages
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
            'data' => $message->load(['user:id,first_name,last_name,username,position,office_id', 'user.office'])
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

    public function addAttachment(Request $request, Document $document): JsonResponse
    {
        $request->validate([
            'file' => 'required|file',
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

    public function deleteAttachment(Request $request, \App\Models\DocumentAttachment $attachment): JsonResponse
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

        $count = \App\Models\DocumentAcknowledgement::query()
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhere('assigned_name', $user->full_name);
            })
            ->whereNull('acknowledged_at')
            ->count();

        return response()->json([
            'count' => $count
        ]);
    }

    public function acknowledge(Request $request, Document $document): JsonResponse
    {
        $user = $request->user();

        /** @var \App\Models\DocumentAcknowledgement|null $acknowledgement */
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
                'remarks' => 'Acknowledged/Opened by recipient.'
            ]);

            DocumentHistory::record($document, 'ACKNOWLEDGED', $user, [
                'remarks' => 'Document opened and acknowledged by recipient.',
                'status_before' => $document->status,
                'status_after' => $document->status,
            ]);
        }

        return response()->json([
            'message' => 'Document acknowledged successfully.',
            'document' => $document->fresh()
        ]);
    }
}
