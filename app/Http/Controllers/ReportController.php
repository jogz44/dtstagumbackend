<?php

namespace App\Http\Controllers;

use App\Models\Division;
use App\Models\Document;
use App\Models\Office;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $range = strtolower((string) ($request->query('range', 'this_month')));
        $from = $request->query('from');
        $to = $request->query('to');
        $selectedDivision = $request->query('division');
        $forceGlobal = strtolower((string) ($request->query('global', 'false'))) === 'true';

        $baseQuery = Document::query();

        // If 'global' flag is set, do not restrict documents by created_at (include existing/older documents).
        if (! $forceGlobal) {
            // Map the requested preset date range to a date window.
            if (!empty($from) || !empty($to) || $range === 'custom') {
                $dateFrom = $from ? Carbon::parse($from)->startOfDay() : null;
                $dateTo = $to ? Carbon::parse($to)->endOfDay() : null;

                if ($dateFrom) {
                    $baseQuery->whereDate('created_at', '>=', $dateFrom->toDateString());
                }

                if ($dateTo) {
                    $baseQuery->whereDate('created_at', '<=', $dateTo->toDateString());
                }
            } else {
                $today = Carbon::today();
                $start = null;
                $end = null;

                switch ($range) {
                    case 'today':
                        $start = $today->copy()->startOfDay();
                        $end = $today->copy()->endOfDay();
                        break;

                    case 'last_7_days':
                        $start = $today->copy()->subDays(6)->startOfDay();
                        $end = $today->copy()->endOfDay();
                        break;

                    case 'last_30_days':
                        $start = $today->copy()->subDays(29)->startOfDay();
                        $end = $today->copy()->endOfDay();
                        break;

                    case 'this_year':
                        $start = $today->copy()->startOfYear();
                        $end = $today->copy()->endOfYear();
                        break;

                    case 'this_month':
                    default:
                        $start = $today->copy()->startOfMonth();
                        $end = $today->copy()->endOfMonth();
                        break;
                }

                if ($start && $end) {
                    $baseQuery->whereBetween('created_at', [$start, $end]);
                }
            }
        }

        if (!empty($selectedDivision) && strtolower((string) $selectedDivision) !== 'all division') {
            $baseQuery->where('division_assignment', $selectedDivision);
        }

        // Range-limited counts used for panels/breakdowns
        $totalDocuments = (clone $baseQuery)->count();
        $activeUsersCount = User::where('is_active', true)->count();
        $totalOffices = Office::count();
        $totalDivisions = Division::count();

        // Global counts (not limited by created_at range) so Reports can surface the same
        // summary numbers as the Dashboard when desired. Division filter still applies.
        $globalQuery = Document::query();
        if (!empty($selectedDivision) && strtolower((string) $selectedDivision) !== 'all division') {
            $globalQuery->where('division_assignment', $selectedDivision);
        }

        $globalTotalDocuments = (clone $globalQuery)->count();

        $activeStatuses = ['registered', 'outgoing', 'incoming', 'pending', 'in progress', 'in_transit', 'received', 'in_process'];
        $terminalStatuses = ['approved', 'archived', 'completed', 'released'];

        $globalPending = (clone $globalQuery)
            ->whereIn(DB::raw('LOWER(status)'), $activeStatuses)
            ->count();

        $globalOverdue = (clone $globalQuery)
            ->whereNotIn(DB::raw('LOWER(status)'), $terminalStatuses)
            ->whereNotNull('due_date')
            ->where('due_date', '<=', Carbon::today()->format('Y-m-d'))
            ->count();

        $thisMonthStart = Carbon::now()->startOfMonth();
        $thisMonthEnd = Carbon::now()->endOfMonth();

        $globalCompletedThisMonth = (clone $globalQuery)
            ->whereIn(DB::raw('LOWER(status)'), ['completed', 'released', 'approved'])
            ->whereBetween('updated_at', [$thisMonthStart, $thisMonthEnd])
            ->count();

        $kpiCards = [
            [
                'label' => 'Total Documents',
                'value' => (string) $totalDocuments,
                'note' => 'System wide registered documents',
                'indicator' => 'bg-green',
            ],
            [
                'label' => 'Active Users',
                'value' => (string) $activeUsersCount,
                'note' => "Across {$totalOffices} offices and {$totalDivisions} divisions",
                'indicator' => 'bg-blue',
            ],
            [
                'label' => 'Total Offices',
                'value' => (string) $totalOffices,
                'note' => 'Active organizational offices',
                'indicator' => 'bg-amber',
            ],
            [
                'label' => 'Total Divisions',
                'value' => (string) $totalDivisions,
                'note' => 'Active operational divisions',
                'indicator' => 'bg-purple',
            ],
        ];

        $completedCount = (clone $baseQuery)->where(function ($query) {
            $query->where('status', 'Completed')
                ->orWhere('status', 'RELEASED');
        })->count();

        $inProgressCount = (clone $baseQuery)->where(function ($query) {
            $query->where('status', 'In Progress')
                ->orWhere('status', 'IN_TRANSIT');
        })->count();

        $pendingCount = (clone $baseQuery)->where(function ($query) {
            $query->where('status', 'Pending')
                ->orWhere('status', 'RECEIVED');
        })->count();

        $otherCount = max(0, $totalDocuments - ($completedCount + $inProgressCount + $pendingCount));

        $breakdownPanels = [
            [
                'key' => 'document-status',
                'category' => 'Documents',
                'title' => 'Status Breakdown',
                'total' => $totalDocuments,
                'totalLabel' => 'Total Docs',
                'items' => [
                    ['label' => 'Completed', 'value' => $completedCount, 'colorHex' => '#4caf50'],
                    ['label' => 'In Progress', 'value' => $inProgressCount, 'colorHex' => '#2196f3'],
                    ['label' => 'Pending', 'value' => $pendingCount, 'colorHex' => '#ff9800'],
                    ['label' => 'Others', 'value' => $otherCount, 'colorHex' => '#9e9e9e'],
                ],
            ],
        ];

        $divisionBlocks = Division::withCount('users')
            ->limit(6)
            ->get()
            ->map(function ($division) {
                return [
                    'title' => $division->name,
                    'total' => $division->users_count,
                    'color' => 'bg-green',
                    'items' => [
                        ['label' => 'Users', 'value' => $division->users_count],
                    ],
                ];
            })
            ->toArray();

        $recentQuery = (clone $baseQuery)->latest();
        // If the request is not explicitly global, limit recent documents for performance.
        if (!($request->query('global', 'false') === 'true')) {
            $recentQuery->limit(10);
        }

        $recentDocuments = $recentQuery
            ->get()
            ->map(function ($doc) {
                return [
                    'id' => $doc->id,
                    'trackingId' => $doc->tracking_number,
                    'title' => $doc->description ?? $doc->document_type ?? 'Untitled Document',
                    'division' => $doc->division_assignment ?? 'Unassigned',
                    'priority' => $doc->priority_level ?? 'Normal',
                    'status' => $doc->status ?? 'Pending',
                    'received' => $doc->created_at?->toIso8601String(),
                ];
            })
            ->toArray();

        // Server-side aggregates for workload charts. Use the same baseQuery scope (which may
        // be date-limited or global depending on the request) but compute grouped counts
        // so the frontend can render accurate bar charts without fetching the entire document list.
        $divisionExpr = "COALESCE(division_assignment, office_assignment, 'Unassigned')";

        $activeByDivision = (clone $baseQuery)
            ->select(DB::raw("{$divisionExpr} as grp"), DB::raw('COUNT(*) as cnt'))
            ->groupBy('grp')
            ->pluck('cnt', 'grp')
            ->toArray();

        $terminalStatuses = ['approved', 'archived', 'completed', 'released'];

        $overdueByDivision = (clone $baseQuery)
            ->whereNotIn(DB::raw('LOWER(status)'), $terminalStatuses)
            ->whereNotNull('due_date')
            ->where('due_date', '<=', Carbon::today()->format('Y-m-d'))
            ->select(DB::raw("{$divisionExpr} as grp"), DB::raw('COUNT(*) as cnt'))
            ->groupBy('grp')
            ->pluck('cnt', 'grp')
            ->toArray();

        return response()->json([
            'kpiCards' => $kpiCards,
            'breakdownPanels' => $breakdownPanels,
            'divisionBlocks' => $divisionBlocks,
            'documents' => $recentDocuments,
            // Provide a dashboard-style summary with global counts so frontend can
            // display totals/overdue/pending that match the Dashboard when desired.
            'summary' => [
                'total_documents' => $globalTotalDocuments,
                'pending' => $globalPending,
                'overdue' => $globalOverdue,
                'completed_this_month' => $globalCompletedThisMonth,
            ],

            // Aggregated counts by division for workload charts. This lets the frontend
            // render accurate bar charts and division breakdowns without fetching every document.
            'aggregates' => [
                'active_by_division' => $activeByDivision,
                'overdue_by_division' => $overdueByDivision,
            ],
        ]);
    }
}
