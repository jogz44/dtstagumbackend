<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentHistory;
use App\Models\User;
use App\Services\DocumentTrackingService;
use App\Services\UserAuditAccess;
use App\Support\RoleNames;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentTrackingController extends Controller
{
    public function __construct(
        protected DocumentTrackingService $trackingService
    ) {}

    /**
     * Display a paginated list of tracking records for the authenticated user's role & scope.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'scope',
            'status',
            'search',
            'sort_by',
            'sort_order',
            'page',
            'per_page',
        ]);

        $result = $this->trackingService->getPaginatedTrackingList($request->user(), $filters);

        return response()->json($result);
    }

    /**
     * Display role-scoped tracking statistics (Total, Incoming, Outgoing, Pending, Overdue, Completed).
     */
    public function statistics(Request $request): JsonResponse
    {
        $stats = $this->trackingService->getTrackingStatistics($request->user());

        return response()->json($stats);
    }

    /**
     * Display the comprehensive tracking portal payload for a specific document.
     */
    public function show(Request $request, Document $document, UserAuditAccess $access): JsonResponse
    {
        $viewer = $request->user();

        $this->authorizeTrackingRead($viewer, $document, $access);

        $summary = $this->trackingService->getTrackingSummary($document, $viewer);

        return response()->json($summary);
    }

    /**
     * Search documents for tracking with strict authorization filtering.
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'required|string|min:1|max:255',
        ]);

        $searchQuery = trim((string) $request->query('q'));

        $filters = [
            'search' => $searchQuery,
            'per_page' => 20,
        ];

        $result = $this->trackingService->getPaginatedTrackingList($request->user(), $filters);

        return response()->json([
            'data' => $result['data'],
        ]);
    }

    /**
     * Verify that the user is authorized to track the document.
     */
    private function authorizeTrackingRead(User $authUser, Document $document, UserAuditAccess $access): void
    {
        if ($authUser->hasRole(RoleNames::ADMIN)) {
            return;
        }

        // 1. Created by user
        if ($document->created_by === $authUser->id) {
            return;
        }

        // 2. Creator in user's office
        if ($authUser->office_id && $document->creator?->office_id === $authUser->office_id) {
            return;
        }

        // 3. Document assigned to user's office or assigned user directly
        $officeName = $authUser->office?->name;
        if ($officeName && $document->office_assignment === $officeName) {
            return;
        }

        if ($document->assigned_user === $authUser->full_name || $document->assigned_user === $authUser->id) {
            return;
        }

        // 4. Involved in history events
        $isInvolved = DocumentHistory::query()
            ->where('document_id', $document->id)
            ->involvingUser($authUser)
            ->exists();

        if ($isInvolved) {
            return;
        }

        // 5. Can audit an involved user
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

        $canAuditInvolved = User::query()
            ->whereIn('id', $involvedUserIds)
            ->get(['id', 'office_id', 'division_id'])
            ->contains(fn (User $targetUser) => $access->canRead($authUser, $targetUser));

        if ($canAuditInvolved) {
            return;
        }

        abort(403, 'You do not have permission to view tracking for this document.');
    }
}
