<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\User;
use App\Services\DocumentAssignmentService;
use App\Services\DocumentStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentWorkflowController extends Controller
{
    public function assign(Request $request, Document $document, DocumentAssignmentService $assignments): JsonResponse
    {
        $validated = $request->validate([
            'assigned_user_id' => ['required', 'uuid', 'exists:users,id'],
            'notes' => ['nullable', 'string'],
        ]);

        $assignment = $assignments->assign(
            $document,
            User::findOrFail($validated['assigned_user_id']),
            $request->user(),
            null,
            $validated['notes'] ?? null,
        );

        return response()->json([
            'message' => 'Document assigned successfully.',
            'assignment' => $assignment->load(['assignedUser', 'assignedBy']),
        ], 201);
    }

    public function transition(Request $request, Document $document, DocumentStatusService $statuses): JsonResponse
    {
        $validated = $request->validate([
            'to_status' => ['required', 'in:received,assigned,processing,overdue,returned,rejected,revised'],
            'reason' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ]);

        $history = $statuses->transition(
            $document,
            $validated['to_status'],
            $request->user(),
            $validated['reason'] ?? null,
            $validated['metadata'] ?? null,
        );

        return response()->json([
            'message' => 'Document status updated successfully.',
            'status_history' => $history,
            'document' => $document->fresh(),
        ]);
    }

    public function complete(Request $request, Document $document, DocumentStatusService $statuses): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ]);

        $history = $statuses->complete(
            $document,
            $request->user(),
            null,
            $validated['reason'] ?? null,
            $validated['metadata'] ?? null,
        );

        return response()->json([
            'message' => 'Document completed successfully.',
            'status_history' => $history,
            'document' => $document->fresh(['completedBy']),
        ]);
    }
}
