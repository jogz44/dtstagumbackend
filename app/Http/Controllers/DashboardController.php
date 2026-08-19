<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function dashboard(Request $request)
    {
        $range = $request->input('range', 'Today');
        $start = match ($range) {
            'This Week' => Carbon::now()->startOfWeek(),
            'This Month' => Carbon::now()->startOfMonth(),
            'This Quarter' => Carbon::now()->startOfQuarter(),
            default => Carbon::today(),
        };
        $end = match ($range) {
            'This Week' => Carbon::now()->endOfWeek(),
            'This Month' => Carbon::now()->endOfMonth(),
            'This Quarter' => Carbon::now()->endOfQuarter(),
            default => Carbon::today()->endOfDay(),
        };

        $documentsQuery = \App\Models\Document::with('attachments')
            ->whereBetween('created_at', [$start, $end]);
        $hasDueDate = Schema::hasColumn('documents', 'due_date');
        if ($hasDueDate && $request->filled('from')) {
            $documentsQuery->whereDate('due_date', '>=', $request->input('from'));
        }
        if ($hasDueDate && $request->filled('to')) {
            $documentsQuery->whereDate('due_date', '<=', $request->input('to'));
        }
        $documents = $documentsQuery
            ->latest()
            ->get();
        $activeStatuses = ['registered', 'pending', 'processing', 'outgoing', 'returned'];
        $today = Carbon::today();

        $documents = $documents->map(function ($document) use ($today, $activeStatuses) {
            $daysRemaining = $document->due_date ? $today->diffInDays($document->due_date, false) : null;
            $isOverdue = strtolower((string) $document->status) === 'overdue'
                || ($daysRemaining !== null && $daysRemaining < 0 && in_array(strtolower((string) $document->status), $activeStatuses, true));
            $urgency = $isOverdue || ($daysRemaining !== null && $daysRemaining <= 3)
                ? 'critical'
                : (($daysRemaining !== null && $daysRemaining <= 7) ? 'warning' : (($daysRemaining !== null && $daysRemaining <= 15) ? 'safe' : 'normal'));

            return [
                'id' => $document->id,
                'tracking' => $document->tracking_number,
                'particular' => $document->description,
                'office' => $document->office_assignment ?: 'No office assigned',
                'division' => $document->division_assignment ?: 'Unassigned',
                'type' => $document->document_type,
                'priority' => $document->priority_level,
                'status' => ucfirst((string) $document->status),
                'date' => optional($document->created_at)->format('M d, Y'),
                'created_at' => optional($document->created_at)->toIso8601String(),
                'due_date' => $document->due_date?->format('Y-m-d'),
                'days_remaining' => $daysRemaining,
                'is_overdue' => $isOverdue,
                'urgency' => $urgency,
                'attention_message' => $isOverdue ? 'Deadline passed' : ($daysRemaining === null ? 'No deadline set' : ($daysRemaining === 0 ? 'Due today' : "Due in {$daysRemaining} day" . ($daysRemaining === 1 ? '' : 's'))),
                'remarks' => $document->remarks,
                'attachments' => $document->attachments->map(fn ($attachment) => [
                    'id' => $attachment->id,
                    'name' => $attachment->original_name,
                    'mime_type' => $attachment->mime_type,
                    'url' => url('/api/attachments/' . $attachment->id),
                ])->values(),
            ];
        });

        $attentionQuery = \App\Models\Document::with('attachments')
            ->where(function ($query) use ($activeStatuses, $hasDueDate) {
                $query->whereIn('status', $activeStatuses)
                    ->orWhere('status', 'overdue')
                    ->when($hasDueDate, function ($query) use ($activeStatuses) {
                        $query->orWhere(function ($dueDateQuery) use ($activeStatuses) {
                            $dueDateQuery->whereIn('status', $activeStatuses)
                                ->whereDate('due_date', '<', Carbon::today());
                        });
                    });
            });
        if ($hasDueDate && $request->filled('from')) {
            $attentionQuery->whereDate('due_date', '>=', $request->input('from'));
        }
        if ($hasDueDate && $request->filled('to')) {
            $attentionQuery->whereDate('due_date', '<=', $request->input('to'));
        }

        $attentionDocuments = $attentionQuery->latest()->limit(50)->get()->map(function ($document) use ($today, $activeStatuses) {
            $daysRemaining = $document->due_date ? $today->diffInDays($document->due_date, false) : null;
            $isOverdue = strtolower((string) $document->status) === 'overdue'
                || ($daysRemaining !== null && $daysRemaining < 0 && in_array(strtolower((string) $document->status), $activeStatuses, true));
            $urgency = $isOverdue || ($daysRemaining !== null && $daysRemaining <= 3)
                ? 'critical'
                : (($daysRemaining !== null && $daysRemaining <= 7) ? 'warning' : (($daysRemaining !== null && $daysRemaining <= 15) ? 'safe' : 'normal'));

            return [
                'id' => $document->id,
                'tracking' => $document->tracking_number,
                'particular' => $document->description,
                'office' => $document->office_assignment ?: 'No office assigned',
                'division' => $document->division_assignment ?: 'Unassigned',
                'type' => $document->document_type,
                'priority' => $document->priority_level,
                'status' => ucfirst((string) $document->status),
                'date' => optional($document->created_at)->format('M d, Y'),
                'due_date' => $document->due_date?->format('Y-m-d'),
                'days_remaining' => $daysRemaining,
                'is_overdue' => $isOverdue,
                'urgency' => $urgency,
                'attention_message' => $isOverdue ? 'Deadline passed' : ($daysRemaining === null ? 'Action required' : ($daysRemaining === 0 ? 'Due today' : "Due in {$daysRemaining} day" . ($daysRemaining === 1 ? '' : 's'))),
                'remarks' => $document->remarks,
                'attachments' => $document->attachments->map(fn ($attachment) => [
                    'id' => $attachment->id,
                    'name' => $attachment->original_name,
                    'mime_type' => $attachment->mime_type,
                    'url' => url('/api/attachments/' . $attachment->id),
                ])->values(),
            ];
        });

        $divisionNames = \App\Models\Division::query()->orderBy('name')->pluck('name');
        $workload = $divisionNames->map(function ($name) use ($documents, $activeStatuses) {
            $divisionDocuments = $documents->filter(fn ($document) => data_get($document, 'division') === $name);
            return [
                'name' => $name,
                'active' => $divisionDocuments->filter(fn ($document) => in_array(strtolower((string) data_get($document, 'status')), $activeStatuses, true) && !data_get($document, 'is_overdue'))->count(),
                'overdue' => $divisionDocuments->filter(fn ($document) => data_get($document, 'is_overdue') === true)->count(),
            ];
        })->values();

        $pending = $documents->filter(fn ($document) => in_array(strtolower($document['status']), $activeStatuses, true) && !$document['is_overdue'])->count();
        $overdue = $documents->where('is_overdue', true)->count();

        return response()->json([
            'range' => $range,
            'range_label' => $start->format('M d, Y') . ' - ' . $end->format('M d, Y'),
            'total' => $documents->count(),
            'pending' => ['value' => $pending, 'highlightText' => $pending . ' active document' . ($pending === 1 ? '' : 's')],
            'overdue' => ['value' => $overdue, 'highlightText' => $overdue . ' overdue document' . ($overdue === 1 ? '' : 's')],
            'workload' => $workload,
            'documents' => $documents->values(),
            'attention_documents' => $attentionDocuments->values(),
        ]);
    }

    /**
     * Dashboard Summary
     */
    public function summary(Request $request)
{
    $range = $request->input('range', 'Today');

    $query = DB::table('documents');

    switch ($range) {
        case 'This Week':
            $query->whereBetween('created_at', [
                Carbon::now()->startOfWeek(),
                Carbon::now()->endOfWeek(),
            ]);
            break;

        case 'This Month':
            $query->whereBetween('created_at', [
                Carbon::now()->startOfMonth(),
                Carbon::now()->endOfMonth(),
            ]);
            break;

        case 'This Quarter':
            $query->whereBetween('created_at', [
                Carbon::now()->startOfQuarter(),
                Carbon::now()->endOfQuarter(),
            ]);
            break;

        case 'Today':
        default:
            $query->whereDate('created_at', Carbon::today());
            break;
    }

    /*
    |--------------------------------------------------------------------------
    | TOTAL DOCUMENTS
    |--------------------------------------------------------------------------
    */

    $total = (clone $query)->count();

    /*
    |--------------------------------------------------------------------------
    | INCOMING / OUTGOING
    |--------------------------------------------------------------------------
    |
    | These should NOT use document_type because document_type contains
    | values such as "Application Letter", "Memorandum", etc.
    |
    | For now, determine direction from the document status.
    |
    */

    $incoming = (clone $query)
        ->where('status', 'incoming')
        ->count();

    $outgoing = (clone $query)
        ->where('status', 'outgoing')
        ->count();

    /*
    |--------------------------------------------------------------------------
    | REGISTERED
    |--------------------------------------------------------------------------
    */

    $registered = (clone $query)
        ->where('status', 'registered')
        ->count();

    /*
    |--------------------------------------------------------------------------
    | PENDING
    |--------------------------------------------------------------------------
    |
    | Registered, pending, processing and outgoing documents are considered
    | active/pending documents.
    |
    */

    $pending = (clone $query)
        ->whereIn('status', [
            'registered',
            'pending',
            'processing',
            'outgoing',
        ])
        ->count();

    /*
    |--------------------------------------------------------------------------
    | OVERDUE
    |--------------------------------------------------------------------------
    |
    | There is NO due_date column in the documents table.
    | Therefore, only documents explicitly marked "overdue" are counted.
    |
    */

    $overdue = (clone $query)
        ->where('status', 'overdue')
        ->count();

    return response()->json([
        'total' => $total,

        'incoming' => $incoming,

        'outgoing' => $outgoing,

        'registered' => $registered,

        'pending' => [
            'value' => $pending,
            'highlightText' => $pending . ' active document' .
                ($pending === 1 ? '' : 's'),
        ],

        'overdue' => [
            'value' => $overdue,
            'highlightText' => $overdue . ' requires attention',
        ],
    ]);
}

    /**
     * Recent Documents
     */
    public function recentDocuments(Request $request)
    {
        $documents = DB::table('documents')
            ->leftJoin(
                'document_attachments',
                'documents.id',
                '=',
                'document_attachments.document_id'
            )
            ->select(
                'documents.id',
                'documents.tracking_number',
                'documents.description',
                'documents.document_type',
                'documents.priority_level',
                'documents.status',
                'documents.created_at',
                'documents.office_assignment',
                'documents.division_assignment',

                'document_attachments.id as attachment_id',
                'document_attachments.original_name as attachment_name',
                'document_attachments.mime_type as attachment_mime_type',
                'document_attachments.size as attachment_size'
            )
            ->orderByDesc('documents.created_at')
            ->limit(10)
            ->get();

        return response()->json(
            $documents->map(function ($document) {
                $attachment = null;

                if ($document->attachment_id) {
                    $attachment = [
                        'id' => $document->attachment_id,
                        'name' => $document->attachment_name,
                        'mime_type' => $document->attachment_mime_type,
                        'size' => $document->attachment_size,

                        'url' => url(
                            '/api/documents/attachments/' .
                            $document->attachment_id .
                            '/preview'
                        ),
                    ];
                }

                return [
                    'id' => $document->id,

                    'tracking' => $document->tracking_number,

                    'particular' => $document->description,

                    'office' =>
                        $document->office_assignment
                        ?: 'No office assigned',

                    'division' =>
                        $document->division_assignment
                        ?: 'No division assigned',

                    'type' => $this->formatDocumentType(
                    $document->document_type
                    ),

                    'documentType' => $document->document_type,

                    'priority' => $document->priority_level,

                    'status' => $this->formatStatus(
                        $document->status
                    ),

                    'date' => Carbon::parse(
                        $document->created_at
                    )->format('M d, Y'),

                    'has_attachment' => $attachment !== null,

                    'attachment' => $attachment,
                ];
            })
        );
    }

    /**
     * Recent Activity
     */
    public function recentActivity(Request $request)
    {
        $activities = DB::table('activity_events')
            ->leftJoin(
                'users',
                'activity_events.actor_user_id',
                '=',
                'users.id'
            )
            ->select(
                'activity_events.id',
                'activity_events.activity_type',
                'activity_events.module',
                'activity_events.description',
                'activity_events.outcome',
                'activity_events.occurred_at',

                DB::raw("
                    CASE
                        WHEN users.id IS NOT NULL
                        THEN CONCAT(users.first_name, ' ', users.last_name)
                        ELSE 'System'
                    END AS actor
                ")
            )
            ->orderByDesc('activity_events.occurred_at')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => $activities,
        ]);
    }

    /**
     * Format document status for frontend.
     */
    private function formatStatus($status)
    {
        return match ($status) {
            'registered' => 'Registered',
            'incoming' => 'Incoming',
            'outgoing' => 'Outgoing',
            'pending' => 'Pending',
            'processing' => 'Processing',
            'completed' => 'Completed',
            'overdue' => 'Overdue',
            'archived' => 'Archived',
            default => ucfirst($status ?? 'Unknown'),
        };
    }

    /**
     * Format document type for frontend.
     */
    private function formatDocumentType($type)
{
    return match (strtolower((string) $type)) {
        'incoming' => 'Incoming',
        'outgoing' => 'Outgoing',
        default => 'Document',
    };
}
}