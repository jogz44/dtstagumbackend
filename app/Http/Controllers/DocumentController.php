<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Models\Document;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    public function store(StoreDocumentRequest $request, AuditService $audit): JsonResponse
    {
        $validated = $request->safe()->except('attachments');

        $assignedUserInput = $request->input('assigned_user');
        if (filter_var($request->input('self_assign'), FILTER_VALIDATE_BOOLEAN)) {
            $assignedUser = $request->user()->id;
        } elseif (!empty($assignedUserInput) && $assignedUserInput !== '-Select user-' && $assignedUserInput !== '-Select office first-') {
            $localUser = \App\Models\User::where(function ($query) use ($assignedUserInput) {
                $query->whereRaw("CONCAT(first_name, ' ', last_name) = ?", [$assignedUserInput])
                      ->orWhereRaw("CONCAT(first_name, ' ', middle_name, ' ', last_name) = ?", [$assignedUserInput])
                      ->orWhere('first_name', 'LIKE', '%' . $assignedUserInput . '%')
                      ->orWhere('last_name', 'LIKE', '%' . $assignedUserInput . '%');
            })->first();

            $assignedUser = $localUser ? $localUser->id : $assignedUserInput;
        } else {
            $assignedUser = null;
        }

        $document = DB::transaction(function () use ($request, $validated, $assignedUser) {
            $document = Document::create([
                ...$validated,
                'assigned_user' => $assignedUser,
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

            // 1. Log in document history
            $document->histories()->create([
                'performed_by' => $request->user()->id,
                'action' => 'registered',
                'remarks' => 'Document registered in the system.',
                'from_office' => $document->origin_sender,
                'to_office' => $document->office_assignment,
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
            if (!empty($document->assigned_user)) {
                if (Str::isUuid($document->assigned_user)) {
                    $localUser = \App\Models\User::find($document->assigned_user);
                    $assignedName = $localUser ? $localUser->full_name : 'Assigned User';
                    $userId = $localUser?->id;
                } else {
                    $assignedName = $document->assigned_user;
                    $localUser = \App\Models\User::where(function ($query) use ($assignedName) {
                        $query->whereRaw("CONCAT(first_name, ' ', last_name) = ?", [$assignedName])
                              ->orWhereRaw("CONCAT(first_name, ' ', middle_name, ' ', last_name) = ?", [$assignedName])
                              ->orWhere('first_name', 'LIKE', '%' . $assignedName . '%')
                              ->orWhere('last_name', 'LIKE', '%' . $assignedName . '%');
                    })->first();
                    $userId = $localUser?->id;
                }

                $document->acknowledgements()->create([
                    'user_id' => $userId,
                    'assigned_name' => $assignedName,
                    'remarks' => 'Pending acknowledgement.',
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

        $assigned = $document->assigned_user;
        if (!empty($assigned) && Str::isUuid($assigned)) {
            $user = \App\Models\User::find($assigned);
            $document->assigned_user = $user ? $user->full_name : $assigned;
        }

        return response()->json([
            'message' => 'Document saved successfully.',
            'document' => $document->load('attachments'),
        ], 201);
    }

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
}
