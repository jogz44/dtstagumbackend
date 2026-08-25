<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentAcknowledgement;
use App\Models\DocumentHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DashboardAnalyticsService
{
    public function __construct(
        protected DocumentTrackingService $trackingService
    ) {}

    /**
     * Get complete dashboard analytics payload for the authenticated viewer.
     */
    public function getAnalytics(User $viewer, array $filters = []): array
    {
        $rangeInfo = $this->resolveDateRange($filters);
        $startDate = $rangeInfo['start_carbon'];
        $endDate = $rangeInfo['end_carbon'];

        // Base authorized query for the viewer
        $baseAuthorizedQuery = $this->trackingService->buildAuthorizedDocumentQuery($viewer);

        // Date-filtered query for range-specific stats
        $rangeQuery = (clone $baseAuthorizedQuery)->whereBetween('documents.created_at', [$startDate, $endDate]);

        // 1. KPI Summary
        $summary = $this->calculateSummary($baseAuthorizedQuery, $rangeQuery);

        // 2. Documents by Status
        $statusDistribution = $this->calculateStatusDistribution($rangeQuery);

        // 3. Documents by Direction
        $directionDistribution = $this->calculateDirectionDistribution($viewer, $rangeQuery);

        // 4. Activity Trend (daily breakdown)
        $activityTrend = $this->calculateActivityTrend($viewer, $startDate, $endDate);

        // 5. Top Document Types
        $topDocumentTypes = $this->calculateTopDocumentTypes($rangeQuery);

        // 6. Overdue Trend
        $overdueTrend = $this->calculateOverdueTrend($baseAuthorizedQuery, $startDate, $endDate);

        // 7. Performance Summary
        $performance = $this->calculatePerformanceSummary($baseAuthorizedQuery, $rangeQuery, $startDate, $endDate);

        return [
            'range_info' => [
                'selected_range' => $rangeInfo['selected_range'],
                'date_from' => $rangeInfo['date_from'],
                'date_to' => $rangeInfo['date_to'],
                'label' => $rangeInfo['label'],
            ],
            'summary' => $summary,
            'status_distribution' => $statusDistribution,
            'direction_distribution' => $directionDistribution,
            'activity_trend' => $activityTrend,
            'top_document_types' => $topDocumentTypes,
            'overdue_trend' => $overdueTrend,
            'performance' => $performance,
        ];
    }

    /**
     * Resolve and validate date range parameters.
     */
    public function resolveDateRange(array $filters): array
    {
        $range = strtolower((string) ($filters['range'] ?? 'this_month'));
        $today = Carbon::today();

        if ($range === 'custom' || (! empty($filters['date_from']) && ! empty($filters['date_to']))) {
            $dateFromStr = $filters['date_from'] ?? null;
            $dateToStr = $filters['date_to'] ?? null;

            if (! $dateFromStr || ! $dateToStr) {
                throw new InvalidArgumentException('Both date_from and date_to are required for custom date range.');
            }

            $start = Carbon::parse($dateFromStr)->startOfDay();
            $end = Carbon::parse($dateToStr)->endOfDay();

            if ($start->gt($end)) {
                throw new InvalidArgumentException('Start date (date_from) cannot be after end date (date_to).');
            }

            return [
                'selected_range' => 'custom',
                'date_from' => $start->format('Y-m-d'),
                'date_to' => $end->format('Y-m-d'),
                'start_carbon' => $start,
                'end_carbon' => $end,
                'label' => $start->format('M d, Y') . ' - ' . $end->format('M d, Y'),
            ];
        }

        switch ($range) {
            case 'today':
                $start = $today->copy()->startOfDay();
                $end = $today->copy()->endOfDay();
                $label = $start->format('M d, Y');
                break;
            case 'last_7_days':
                $start = $today->copy()->subDays(6)->startOfDay();
                $end = $today->copy()->endOfDay();
                $label = 'Last 7 Days (' . $start->format('M d') . ' - ' . $end->format('M d, Y') . ')';
                break;
            case 'this_week':
                $start = Carbon::now()->startOfWeek();
                $end = Carbon::now()->endOfWeek();
                $label = 'This Week (' . $start->format('M d') . ' - ' . $end->format('M d, Y') . ')';
                break;
            case 'last_30_days':
                $start = $today->copy()->subDays(29)->startOfDay();
                $end = $today->copy()->endOfDay();
                $label = 'Last 30 Days';
                break;
            case 'this_year':
                $start = Carbon::now()->startOfYear();
                $end = Carbon::now()->endOfYear();
                $label = 'This Year (' . $start->format('Y') . ')';
                break;
            case 'this_month':
            default:
                $range = 'this_month';
                $start = Carbon::now()->startOfMonth();
                $end = Carbon::now()->endOfMonth();
                $label = 'This Month (' . $start->format('M Y') . ')';
                break;
        }

        return [
            'selected_range' => $range,
            'date_from' => $start->format('Y-m-d'),
            'date_to' => $end->format('Y-m-d'),
            'start_carbon' => $start,
            'end_carbon' => $end,
            'label' => $label,
        ];
    }

    private function calculateSummary(Builder $baseQuery, Builder $rangeQuery): array
    {
        $totalDocuments = (clone $rangeQuery)->count();

        $activeStatuses = ['registered', 'outgoing', 'incoming', 'pending', 'in progress', 'in_transit', 'received', 'in_process'];
        $terminalStatuses = ['completed', 'released', 'archived', 'approved'];

        $pending = (clone $baseQuery)
            ->whereIn(DB::raw('LOWER(status)'), $activeStatuses)
            ->count();

        $overdue = (clone $baseQuery)
            ->whereNotIn(DB::raw('LOWER(status)'), $terminalStatuses)
            ->whereNotNull('due_date')
            ->where('due_date', '<', Carbon::today()->format('Y-m-d'))
            ->count();

        $thisMonthStart = Carbon::now()->startOfMonth();
        $thisMonthEnd = Carbon::now()->endOfMonth();

        $completedThisMonth = (clone $baseQuery)
            ->whereIn(DB::raw('LOWER(status)'), ['completed', 'released', 'approved'])
            ->whereBetween('updated_at', [$thisMonthStart, $thisMonthEnd])
            ->count();

        return [
            'total_documents' => $totalDocuments,
            'pending' => $pending,
            'overdue' => $overdue,
            'completed_this_month' => $completedThisMonth,
        ];
    }

    private function calculateStatusDistribution(Builder $rangeQuery): array
    {
        $rawCounts = (clone $rangeQuery)
            ->select(DB::raw('LOWER(status) as norm_status'), DB::raw('COUNT(*) as count'))
            ->groupBy('norm_status')
            ->pluck('count', 'norm_status')
            ->toArray();

        $distribution = [
            'registered' => 0,
            'routed' => 0,
            'received' => 0,
            'in_process' => 0,
            'completed' => 0,
            'archived' => 0,
        ];

        foreach ($rawCounts as $status => $count) {
            if (in_array($status, ['registered'], true)) {
                $distribution['registered'] += $count;
            } elseif (in_array($status, ['outgoing', 'transferred', 'forwarded', 'routed'], true)) {
                $distribution['routed'] += $count;
            } elseif (in_array($status, ['received'], true)) {
                $distribution['received'] += $count;
            } elseif (in_array($status, ['incoming', 'pending', 'in progress', 'in_process', 'in_transit'], true)) {
                $distribution['in_process'] += $count;
            } elseif (in_array($status, ['completed', 'released', 'approved'], true)) {
                $distribution['completed'] += $count;
            } elseif (in_array($status, ['archived'], true)) {
                $distribution['archived'] += $count;
            } else {
                $distribution['in_process'] += $count;
            }
        }

        return $distribution;
    }

    private function calculateDirectionDistribution(User $viewer, Builder $rangeQuery): array
    {
        $incomingQuery = clone $rangeQuery;
        $this->trackingService->applyIncomingCondition($incomingQuery, $viewer);
        $incoming = $incomingQuery->count();

        $outgoingQuery = clone $rangeQuery;
        $this->trackingService->applyOutgoingCondition($outgoingQuery, $viewer);
        $outgoing = $outgoingQuery->count();

        return [
            'incoming' => $incoming,
            'outgoing' => $outgoing,
        ];
    }

    private function calculateActivityTrend(User $viewer, Carbon $startDate, Carbon $endDate): array
    {
        $trend = [];
        $current = $startDate->copy()->startOfDay();
        $end = $endDate->copy()->startOfDay();

        // Limit maximum days in trend calculation to prevent runaway loops
        $daysCount = $current->diffInDays($end) + 1;
        if ($daysCount > 90) {
            $current = $end->copy()->subDays(89);
        }

        while ($current->lte($end)) {
            $dayStart = $current->copy()->startOfDay();
            $dayEnd = $current->copy()->endOfDay();
            $dateStr = $current->format('Y-m-d');

            $dayBaseQuery = $this->trackingService->buildAuthorizedDocumentQuery($viewer);

            $incomingCount = (clone $dayBaseQuery)
                ->whereBetween('created_at', [$dayStart, $dayEnd])
                ->count();

            $outgoingCount = (clone $dayBaseQuery)
                ->whereIn(DB::raw('LOWER(status)'), ['outgoing', 'transferred', 'released'])
                ->whereBetween('updated_at', [$dayStart, $dayEnd])
                ->count();

            $completedCount = (clone $dayBaseQuery)
                ->whereIn(DB::raw('LOWER(status)'), ['completed', 'released', 'approved'])
                ->whereBetween('updated_at', [$dayStart, $dayEnd])
                ->count();

            $trend[] = [
                'date' => $dateStr,
                'incoming' => $incomingCount,
                'outgoing' => $outgoingCount,
                'completed' => $completedCount,
            ];

            $current->addDay();
        }

        return $trend;
    }

    private function calculateTopDocumentTypes(Builder $rangeQuery): array
    {
        $types = (clone $rangeQuery)
            ->select('document_type', DB::raw('COUNT(*) as count'))
            ->groupBy('document_type')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        $totalCount = $types->sum('count');

        return $types->map(function ($item) use ($totalCount) {
            $type = $item->document_type ?: 'General Document';
            $count = (int) $item->count;
            $percentage = $totalCount > 0 ? round(($count / $totalCount) * 100, 1) : 0;

            return [
                'type' => $type,
                'count' => $count,
                'percentage' => $percentage,
            ];
        })->toArray();
    }

    private function calculateOverdueTrend(Builder $baseQuery, Carbon $startDate, Carbon $endDate): array
    {
        $trend = [];
        $current = $startDate->copy()->startOfDay();
        $end = $endDate->copy()->startOfDay();

        $daysCount = $current->diffInDays($end) + 1;
        if ($daysCount > 90) {
            $current = $end->copy()->subDays(89);
        }

        while ($current->lte($end)) {
            $dateStr = $current->format('Y-m-d');

            $count = (clone $baseQuery)
                ->whereNotIn(DB::raw('LOWER(status)'), ['completed', 'released', 'archived', 'approved'])
                ->whereNotNull('due_date')
                ->where('due_date', '<', $dateStr)
                ->whereDate('created_at', '<=', $dateStr)
                ->count();

            $trend[] = [
                'date' => $dateStr,
                'count' => $count,
            ];

            $current->addDay();
        }

        return $trend;
    }

    private function calculatePerformanceSummary(Builder $baseQuery, Builder $rangeQuery, Carbon $startDate, Carbon $endDate): array
    {
        // 1. Average processing time in days for completed documents
        $completedDocs = (clone $rangeQuery)
            ->whereIn(DB::raw('LOWER(status)'), ['completed', 'released', 'approved'])
            ->get(['created_at', 'updated_at']);

        if ($completedDocs->isNotEmpty()) {
            $totalDays = $completedDocs->sum(function ($doc) {
                return $doc->created_at ? max(0, $doc->created_at->diffInDays($doc->updated_at)) : 0;
            });
            $avgProcessingDays = round($totalDays / $completedDocs->count(), 1);
        } else {
            $avgProcessingDays = 0.0;
        }

        // 2. Acknowledgement Rate
        $rangeDocIds = (clone $rangeQuery)->pluck('id');
        if ($rangeDocIds->isNotEmpty()) {
            $acknowledgedCount = DocumentAcknowledgement::whereIn('document_id', $rangeDocIds)->distinct('document_id')->count('document_id');
            $ackRate = round(($acknowledgedCount / $rangeDocIds->count()) * 100, 1);
        } else {
            $ackRate = 0.0;
        }

        // 3. Documents Per Day
        $daysInRange = max(1, $startDate->diffInDays($endDate) + 1);
        $totalRangeDocs = (clone $rangeQuery)->count();
        $docsPerDay = round($totalRangeDocs / $daysInRange, 1);

        // 4. Overdue Rate
        $activeStatuses = ['registered', 'outgoing', 'incoming', 'pending', 'in progress', 'in_transit', 'received', 'in_process'];
        $activeDocsCount = (clone $baseQuery)->whereIn(DB::raw('LOWER(status)'), $activeStatuses)->count();
        $overdueCount = (clone $baseQuery)
            ->whereNotIn(DB::raw('LOWER(status)'), ['completed', 'released', 'archived', 'approved'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', Carbon::today()->format('Y-m-d'))
            ->count();

        $overdueRate = $activeDocsCount > 0 ? round(($overdueCount / $activeDocsCount) * 100, 1) : 0.0;

        return [
            'average_processing_days' => $avgProcessingDays,
            'acknowledgement_rate' => $ackRate,
            'documents_per_day' => $docsPerDay,
            'overdue_rate' => $overdueRate,
        ];
    }
}
