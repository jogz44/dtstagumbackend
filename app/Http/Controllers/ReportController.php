<?php

namespace App\Http\Controllers;

use App\Models\Division;
use App\Models\Document;
use App\Models\Office;
use App\Models\User;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    public function __construct(
        protected ReportService $reportService,
    ) {}

    /**
     * Main reports index & dashboard endpoint.
     */
    public function index(Request $request): JsonResponse
    {
        $rawFilters = $request->only([
            'from',
            'to',
            'office_id',
            'division_id',
            'division',
            'priority',
            'status',
            'search',
            'range',
            'global',
        ]);

        $filters = $rawFilters;

        // If a division name string was provided instead of division_id (e.g. from frontend select)
        if (! empty($filters['division']) && empty($filters['division_id'])) {
            $divName = trim((string) $filters['division']);
            if (strtolower($divName) !== 'all divisions' && strtolower($divName) !== 'all division') {
                $divModel = Division::where('name', $divName)->first();
                if ($divModel) {
                    $filters['division_id'] = $divModel->id;
                }
            }
        }

        try {
            $report = $this->reportService->getDashboardReport($filters, $request->user());

            // Build scoped document query for frontend documents list and aggregates
            $scope = $this->reportService->buildDocumentScope($filters, $request->user());

            // Fetch documents with all properties needed by frontend reports page
            $documents = (clone $scope)
                ->latest('created_at')
                ->get()
                ->map(function ($doc) {
                    return [
                        'id' => $doc->id,
                        'tracking_number' => $doc->tracking_number,
                        'trackingId' => $doc->tracking_number,
                        'title' => $doc->description ?? $doc->document_type ?? 'Untitled Document',
                        'description' => $doc->description,
                        'document_type' => $doc->document_type,
                        'office' => $doc->office_assignment ?? $doc->origin_sender ?? 'Unassigned Office',
                        'office_assignment' => $doc->office_assignment,
                        'division' => $doc->division_assignment ?? 'Unassigned Division',
                        'division_assignment' => $doc->division_assignment,
                        'priority' => $doc->priority_level ?? 'Normal',
                        'priority_level' => $doc->priority_level ?? 'Normal',
                        'status' => $doc->status ?? 'Pending',
                        'created_at' => $doc->created_at?->toIso8601String(),
                        'updated_at' => $doc->updated_at?->toIso8601String(),
                        'received' => $doc->created_at?->toIso8601String(),
                        'due_date' => $doc->due_date,
                    ];
                })
                ->values()
                ->toArray();

            // Aggregates for division workloads
            $divisionExpr = "COALESCE(division_assignment, office_assignment, 'Unassigned')";
            $terminalStatuses = ['approved', 'archived', 'completed', 'released'];

            $activeByDivision = (clone $scope)
                ->select(
                    DB::raw("{$divisionExpr} as grp"),
                    DB::raw('COUNT(*) as cnt')
                )
                ->groupBy(DB::raw($divisionExpr))
                ->pluck('cnt', 'grp')
                ->toArray();

            $overdueByDivision = (clone $scope)
                ->whereNotIn(DB::raw('LOWER(status)'), $terminalStatuses)
                ->whereNotNull('due_date')
                ->where('due_date', '<=', Carbon::today('Asia/Manila')->toDateString())
                ->select(
                    DB::raw("{$divisionExpr} as grp"),
                    DB::raw('COUNT(*) as cnt')
                )
                ->groupBy(DB::raw($divisionExpr))
                ->pluck('cnt', 'grp')
                ->toArray();

            // Summary stats for frontend cards
            $globalQuery = Document::query();
            $this->reportService->applyAuthorizationScope($globalQuery, $request->user());
            if (! empty($filters['division_id']) && $filters['division_id'] !== 'all') {
                $divModel = Division::find($filters['division_id']);
                if ($divModel) {
                    $globalQuery->where('division_assignment', $divModel->name);
                }
            }

            $globalTotalDocuments = (clone $globalQuery)->count();
            $globalPending = (clone $globalQuery)
                ->whereIn(DB::raw('LOWER(status)'), ['registered', 'outgoing', 'incoming', 'pending', 'in progress', 'in_transit', 'received', 'in_process'])
                ->count();
            $globalOverdue = (clone $globalQuery)
                ->whereNotIn(DB::raw('LOWER(status)'), $terminalStatuses)
                ->whereNotNull('due_date')
                ->where('due_date', '<=', Carbon::today('Asia/Manila')->toDateString())
                ->count();
            $globalCompletedThisMonth = (clone $globalQuery)
                ->whereIn(DB::raw('LOWER(status)'), ['completed', 'released', 'approved'])
                ->whereBetween('updated_at', [Carbon::now('Asia/Manila')->startOfMonth(), Carbon::now('Asia/Manila')->endOfMonth()])
                ->count();

            // Legacy KPI & Breakdown panels
            $legacyKpiCards = [
                [
                    'label' => 'Total Documents',
                    'value' => (string) ($report['kpis']['total_documents']['value'] ?? 0),
                    'note' => $report['kpis']['total_documents']['comparison_label'] ?? 'System wide registered documents',
                    'indicator' => 'bg-green',
                ],
                [
                    'label' => 'Active Users',
                    'value' => (string) ($report['kpis']['active_users']['value'] ?? 0),
                    'note' => $report['kpis']['active_users']['comparison_label'] ?? 'Across active offices and divisions',
                    'indicator' => 'bg-blue',
                ],
                [
                    'label' => 'Total Offices',
                    'value' => (string) ($report['kpis']['total_offices']['value'] ?? 0),
                    'note' => 'Active organizational offices',
                    'indicator' => 'bg-amber',
                ],
                [
                    'label' => 'Total Divisions',
                    'value' => (string) ($report['kpis']['total_divisions']['value'] ?? 0),
                    'note' => 'Active operational divisions',
                    'indicator' => 'bg-purple',
                ],
            ];

            $legacyBreakdownPanels = [
                [
                    'key' => 'document-status',
                    'category' => 'Documents',
                    'title' => 'Status Breakdown',
                    'total' => $report['meta']['reconciliation']['documents'] ?? 0,
                    'totalLabel' => 'Total Docs',
                    'items' => array_map(function ($item) {
                        return [
                            'label' => $item['status'],
                            'value' => $item['count'],
                            'percent' => $item['percentage'].'%',
                            'colorHex' => $item['color'],
                        ];
                    }, $report['status_breakdown'] ?? []),
                ],
            ];

            $responsePayload = array_merge($report, [
                'documents' => $documents,
                'summary' => [
                    'total_documents' => $globalTotalDocuments,
                    'pending' => $globalPending,
                    'overdue' => $globalOverdue,
                    'completed_this_month' => $globalCompletedThisMonth,
                ],
                'aggregates' => [
                    'active_by_division' => $activeByDivision,
                    'overdue_by_division' => $overdueByDivision,
                ],
                'kpiCards' => $legacyKpiCards,
                'breakdownPanels' => $legacyBreakdownPanels,
                'divisionBlocks' => array_map(function ($item) {
                    return [
                        'title' => $item['division_name'],
                        'total' => $item['count'],
                        'color' => 'bg-green',
                        'items' => [
                            ['label' => 'Documents', 'value' => $item['count']],
                        ],
                    ];
                }, array_slice($report['documents_by_division']['items'] ?? [], 0, 6)),
            ]);

            return response()->json($responsePayload);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Unable to load report data.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Drilldown: documents grouped by office.
     * Used by /reports/offices frontend page.
     */
    public function offices(Request $request): JsonResponse
    {
        $filters = $request->only([
            'from',
            'to',
            'office_id',
            'division_id',
            'priority',
            'status',
            'search',
            'sort',
            'direction',
            'page',
            'per_page',
        ]);

        try {
            $data = $this->reportService->getOfficesReport($filters, $request->user());

            return response()->json($data);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Unable to load offices report data.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Drilldown: documents grouped by division.
     * Used by /reports/divisions frontend page.
     */
    public function divisions(Request $request): JsonResponse
    {
        $filters = $request->only([
            'from',
            'to',
            'office_id',
            'division_id',
            'priority',
            'status',
            'search',
            'sort',
            'direction',
            'page',
            'per_page',
        ]);

        try {
            $data = $this->reportService->getDivisionsReport($filters, $request->user());

            return response()->json($data);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Unable to load divisions report data.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
