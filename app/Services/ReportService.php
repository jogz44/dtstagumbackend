<?php

namespace App\Services;

use App\Models\Division;
use App\Models\Document;
use App\Models\Office;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ReportService
{
    public const STATUS_CATEGORIES = [
        'Completed'   => ['completed', 'COMPLETED', 'released', 'RELEASED', 'approved', 'APPROVED'],
        'In Progress' => ['in_transit', 'IN_TRANSIT', 'outgoing', 'OUTGOING', 'forwarded', 'FORWARDED', 'transferred', 'TRANSFERRED', 'received', 'RECEIVED', 'assigned', 'ASSIGNED'],
        'Pending'     => ['pending', 'PENDING', 'registered', 'REGISTERED'],
        'Returned'    => ['returned', 'RETURNED', 'rejected', 'REJECTED'],
        'Cancelled'   => ['cancelled', 'CANCELLED'],
        'Archived'    => ['archived', 'ARCHIVED'],
    ];

    public const PRIORITY_OPTIONS = [
        ['value' => 'Normal', 'label' => 'Normal'],
        ['value' => 'Urgent', 'label' => 'Urgent'],
        ['value' => 'High Priority', 'label' => 'High Priority'],
    ];

    public const STATUS_OPTIONS = [
        ['value' => 'Completed', 'label' => 'Completed'],
        ['value' => 'In Progress', 'label' => 'In Progress'],
        ['value' => 'Pending', 'label' => 'Pending'],
        ['value' => 'Returned', 'label' => 'Returned'],
        ['value' => 'Cancelled', 'label' => 'Cancelled'],
        ['value' => 'Archived', 'label' => 'Archived'],
        ['value' => 'Others', 'label' => 'Others'],
    ];

    public function getDashboardReport(array $filters, User $user): array
    {
        $scope = $this->buildDocumentScope($filters, $user);

        $totalDocs = (clone $scope)->distinct('documents.id')->count('documents.id');

        $kpis = $this->getKpis($filters, $user, $totalDocs);
        $documentsByOffice = $this->getDocumentsByOffice($filters, $user);
        $documentsByDivision = $this->getDocumentsByDivision($filters, $user);
        $statusBreakdown = $this->getStatusBreakdown($filters, $user, $totalDocs);
        $documentsSent = $this->getSentDocuments($filters, $user);
        $officeDivisionOverview = $this->getOfficeDivisionOverview($filters, $user);
        $officeSummaryCards = $this->getOfficeSummaryCards($filters, $user);

        $statusTotal = array_sum(array_column($statusBreakdown, 'count'));
        $officeTotal = array_sum(array_column($documentsByOffice['items'], 'count'));
        $divisionTotal = array_sum(array_column($documentsByDivision['items'], 'count'));

        $statusMatch = ($totalDocs === $statusTotal);
        $officeMatch = ($totalDocs === $officeTotal);
        $divisionMatch = ($totalDocs === $divisionTotal);
        $reconciled = $statusMatch && $officeMatch && $divisionMatch;

        if (! $reconciled) {
            Log::warning('Report dashboard reconciliation mismatch detected', [
                'total_documents' => $totalDocs,
                'status_total' => $statusTotal,
                'office_total' => $officeTotal,
                'division_total' => $divisionTotal,
                'filters' => $filters,
            ]);
        }

        return [
            'filters' => [
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
                'office_id' => $filters['office_id'] ?? null,
                'division_id' => $filters['division_id'] ?? null,
                'priority' => $filters['priority'] ?? null,
                'status' => $filters['status'] ?? null,
                'search' => $filters['search'] ?? '',
            ],
            'kpis' => $kpis,
            'documents_by_office' => $documentsByOffice,
            'documents_by_division' => $documentsByDivision,
            'status_breakdown' => $statusBreakdown,
            'documents_sent' => $documentsSent,
            'office_division_overview' => $officeDivisionOverview,
            'office_summary_cards' => $officeSummaryCards,
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'reporting_date_field' => 'created_at',
                'permissions' => [
                    'can_filter_office' => $user->hasRole('admin'),
                    'can_filter_division' => $user->hasRole('admin') || $user->hasRole('supervisor'),
                    'scope_level' => $user->hasRole('admin') ? 'all' : ($user->hasRole('supervisor') ? 'office' : 'user'),
                ],
                'available_filters' => [
                    'offices' => $this->getAvailableOffices($user),
                    'divisions' => $this->getAvailableDivisions($user),
                    'priorities' => self::PRIORITY_OPTIONS,
                    'statuses' => self::STATUS_OPTIONS,
                ],
                'reconciliation' => [
                    'documents' => $totalDocs,
                    'statuses' => $statusTotal,
                    'offices' => $officeTotal,
                    'divisions' => $divisionTotal,
                    'status_match' => $statusMatch,
                    'office_match' => $officeMatch,
                    'division_match' => $divisionMatch,
                    'reconciled' => $reconciled,
                ],
            ],
        ];
    }

    public function buildDocumentScope(array $filters, User $user): Builder
    {
        $query = Document::query();

        $this->applyAuthorizationScope($query, $user);
        $this->applyDateScope($query, $filters);
        $this->applyOrganizationScope($query, $filters);
        $this->applyPriorityScope($query, $filters);
        $this->applyStatusScope($query, $filters);
        $this->applySearchScope($query, $filters);

        return $query;
    }

    public function applyAuthorizationScope(Builder $query, User $user): Builder
    {
        if ($user->hasRole('admin')) {
            return $query;
        }

        if ($user->hasRole('supervisor')) {
            if (! $user->office?->name) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where('office_assignment', $user->office->name);
        }

        return $query->where(function ($q) use ($user) {
            $q->where('created_by', $user->id)
              ->orWhere('assigned_user', $user->id)
              ->orWhereHas('acknowledgements', fn ($q2) => $q2->where('user_id', $user->id));
        });
    }

    private function applyDateScope(Builder $query, array $filters): void
    {
        if (! empty($filters['from'])) {
            $fromUtc = Carbon::parse($filters['from'], 'Asia/Manila')->startOfDay()->setTimezone('UTC');
            $query->where('created_at', '>=', $fromUtc);
        }

        if (! empty($filters['to'])) {
            $toUtc = Carbon::parse($filters['to'], 'Asia/Manila')->addDay()->startOfDay()->setTimezone('UTC');
            $query->where('created_at', '<', $toUtc);
        }
    }

    private function applyOrganizationScope(Builder $query, array $filters): void
    {
        $office = null;
        if (! empty($filters['office_id']) && $filters['office_id'] !== 'all') {
            $office = Office::find($filters['office_id']);
            if (! $office) {
                throw ValidationException::withMessages([
                    'office_id' => ['The selected office is invalid.'],
                ]);
            }
            $query->where('office_assignment', $office->name);
        }

        if (! empty($filters['division_id']) && $filters['division_id'] !== 'all') {
            $division = Division::find($filters['division_id']);
            if (! $division) {
                throw ValidationException::withMessages([
                    'division_id' => ['The selected division is invalid.'],
                ]);
            }

            if ($office && $division->office_id !== $office->id) {
                throw ValidationException::withMessages([
                    'division_id' => ['The selected division does not belong to the selected office.'],
                ]);
            }

            $query->where('division_assignment', $division->name);
        }
    }

    private function applyPriorityScope(Builder $query, array $filters): void
    {
        if (! empty($filters['priority']) && $filters['priority'] !== 'all') {
            $priority = $filters['priority'];
            $query->where(function ($q) use ($priority) {
                $q->where('priority_level', $priority)
                  ->orWhere('priority_level', strtolower($priority))
                  ->orWhere('priority_level', ucfirst(strtolower($priority)));

                if (in_array(strtolower($priority), ['high', 'high priority'], true)) {
                    $q->orWhere('priority_level', 'High')->orWhere('priority_level', 'High Priority');
                }
            });
        }
    }

    private function applyStatusScope(Builder $query, array $filters): void
    {
        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $status = $filters['status'];
            if ($status === 'Others') {
                $allMappedAliases = [];
                foreach (self::STATUS_CATEGORIES as $aliases) {
                    $allMappedAliases = array_merge($allMappedAliases, array_map('strtolower', $aliases));
                }
                $query->whereNotIn(DB::raw('LOWER(status)'), array_unique($allMappedAliases));
            } else {
                $rawStatuses = self::STATUS_CATEGORIES[$status] ?? [$status];
                $query->whereIn('status', $rawStatuses);
            }
        }
    }

    private function applySearchScope(Builder $query, array $filters): void
    {
        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('tracking_number', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('document_type', 'like', "%{$search}%")
                  ->orWhere('origin_sender', 'like', "%{$search}%")
                  ->orWhere('sender_name', 'like', "%{$search}%");
            });
        }
    }

    private function getKpis(array $filters, User $user, int $currentTotalDocs): array
    {
        $fromManila = ! empty($filters['from']) ? Carbon::parse($filters['from'], 'Asia/Manila')->startOfDay() : now('Asia/Manila')->subDays(6)->startOfDay();
        $toManila = ! empty($filters['to']) ? Carbon::parse($filters['to'], 'Asia/Manila')->startOfDay() : now('Asia/Manila')->startOfDay();

        $daysDiff = max(1, $fromManila->diffInDays($toManila) + 1);

        $prevFromUtc = $fromManila->copy()->subDays($daysDiff)->setTimezone('UTC');
        $prevToUtc = $fromManila->copy()->setTimezone('UTC');

        $prevFilters = array_merge($filters, [
            'from' => $prevFromUtc->toIso8601String(),
            'to' => $prevToUtc->subSecond()->toIso8601String(),
        ]);

        $prevScope = Document::query();
        $this->applyAuthorizationScope($prevScope, $user);
        $prevScope->where('created_at', '>=', $prevFromUtc)->where('created_at', '<', $prevToUtc);
        $this->applyOrganizationScope($prevScope, $filters);
        $this->applyPriorityScope($prevScope, $filters);
        $this->applyStatusScope($prevScope, $filters);
        $this->applySearchScope($prevScope, $filters);

        $prevTotalDocs = (clone $prevScope)->distinct('documents.id')->count('documents.id');

        $docComp = $this->calculateComparison($currentTotalDocs, $prevTotalDocs, $daysDiff);

        $activeUsersCount = User::where('is_active', true)
            ->when(! empty($filters['office_id']) && $filters['office_id'] !== 'all', fn ($q) => $q->where('office_id', $filters['office_id']))
            ->when(! empty($filters['division_id']) && $filters['division_id'] !== 'all', fn ($q) => $q->where('division_id', $filters['division_id']))
            ->count();

        $prevActiveUsersCount = User::where('is_active', true)->count();
        $userComp = $this->calculateComparison($activeUsersCount, $prevActiveUsersCount, $daysDiff);

        $totalOffices = Office::where('is_active', true)->count();
        $totalDivisions = Division::where('is_active', true)->count();

        $sparklineDates = [];
        for ($i = $daysDiff - 1; $i >= 0; $i--) {
            $sparklineDates[] = $toManila->copy()->subDays($i)->format('Y-m-d');
        }

        $docSparkline = $this->generateDailyTrend(clone $this->buildDocumentScope($filters, $user), $sparklineDates);

        return [
            'total_documents' => [
                'value' => $currentTotalDocs,
                'change_percentage' => $docComp['percentage'],
                'change_type' => $docComp['type'],
                'comparison_label' => $docComp['label'],
                'sparkline' => $docSparkline,
            ],
            'active_users' => [
                'value' => $activeUsersCount,
                'change_percentage' => $userComp['percentage'],
                'change_type' => $userComp['type'],
                'comparison_label' => $userComp['label'],
                'sparkline' => array_fill(0, count($sparklineDates), $activeUsersCount),
            ],
            'total_offices' => [
                'value' => $totalOffices,
                'change_percentage' => null,
                'change_type' => 'no_change',
                'comparison_label' => 'No change vs last week',
                'sparkline' => array_fill(0, count($sparklineDates), $totalOffices),
            ],
            'total_divisions' => [
                'value' => $totalDivisions,
                'change_percentage' => null,
                'change_type' => 'no_change',
                'comparison_label' => 'No change vs last week',
                'sparkline' => array_fill(0, count($sparklineDates), $totalDivisions),
            ],
        ];
    }

    private function getDocumentsByOffice(array $filters, User $user): array
    {
        $scope = $this->buildDocumentScope($filters, $user);

        $fromManila = ! empty($filters['from']) ? Carbon::parse($filters['from'], 'Asia/Manila')->startOfDay() : now('Asia/Manila')->subDays(6)->startOfDay();
        $toManila = ! empty($filters['to']) ? Carbon::parse($filters['to'], 'Asia/Manila')->startOfDay() : now('Asia/Manila')->startOfDay();
        $daysDiff = max(1, $fromManila->diffInDays($toManila) + 1);

        $dates = [];
        for ($i = $daysDiff - 1; $i >= 0; $i--) {
            $dates[] = $toManila->copy()->subDays($i)->format('Y-m-d');
        }

        $rawCounts = (clone $scope)
            ->selectRaw("COALESCE(office_assignment, 'Unassigned Office') as office_name, COUNT(DISTINCT id) as count")
            ->groupBy(DB::raw("COALESCE(office_assignment, 'Unassigned Office')"))
            ->orderByDesc('count')
            ->get();

        $totalDocs = $rawCounts->sum('count');
        $topOffice = $rawCounts->first()?->office_name ?? 'N/A';

        $items = [];
        foreach ($rawCounts as $row) {
            $officeName = $row->office_name;
            $officeModel = Office::where('name', $officeName)->first();
            $count = (int) $row->count;

            $officeScope = (clone $scope)->where(function ($q) use ($officeName) {
                if ($officeName === 'Unassigned Office') {
                    $q->whereNull('office_assignment')->orWhere('office_assignment', '');
                } else {
                    $q->where('office_assignment', $officeName);
                }
            });

            $series = $this->generateDailySeries($officeScope, $dates);

            $items[] = [
                'office_id' => $officeModel?->id ?? 'unassigned',
                'office_name' => $officeName,
                'count' => $count,
                'percentage' => $totalDocs > 0 ? round(($count / $totalDocs) * 100, 2) : 0,
                'series' => $series,
            ];
        }

        return [
            'top_office' => $topOffice,
            'items' => $items,
        ];
    }

    private function getDocumentsByDivision(array $filters, User $user): array
    {
        $scope = $this->buildDocumentScope($filters, $user);

        $fromManila = ! empty($filters['from']) ? Carbon::parse($filters['from'], 'Asia/Manila')->startOfDay() : now('Asia/Manila')->subDays(6)->startOfDay();
        $toManila = ! empty($filters['to']) ? Carbon::parse($filters['to'], 'Asia/Manila')->startOfDay() : now('Asia/Manila')->startOfDay();
        $daysDiff = max(1, $fromManila->diffInDays($toManila) + 1);

        $dates = [];
        for ($i = $daysDiff - 1; $i >= 0; $i--) {
            $dates[] = $toManila->copy()->subDays($i)->format('Y-m-d');
        }

        $rawCounts = (clone $scope)
            ->selectRaw("COALESCE(division_assignment, 'Unassigned Division') as division_name, COUNT(DISTINCT id) as count")
            ->groupBy(DB::raw("COALESCE(division_assignment, 'Unassigned Division')"))
            ->orderByDesc('count')
            ->get();

        $totalDocs = $rawCounts->sum('count');
        $topDivision = $rawCounts->first()?->division_name ?? 'N/A';

        $items = [];
        foreach ($rawCounts as $row) {
            $divisionName = $row->division_name;
            $divisionModel = Division::where('name', $divisionName)->first();
            $count = (int) $row->count;

            $divisionScope = (clone $scope)->where(function ($q) use ($divisionName) {
                if ($divisionName === 'Unassigned Division') {
                    $q->whereNull('division_assignment')->orWhere('division_assignment', '');
                } else {
                    $q->where('division_assignment', $divisionName);
                }
            });

            $series = $this->generateDailySeries($divisionScope, $dates);

            $items[] = [
                'division_id' => $divisionModel?->id ?? 'unassigned',
                'division_name' => $divisionName,
                'count' => $count,
                'percentage' => $totalDocs > 0 ? round(($count / $totalDocs) * 100, 2) : 0,
                'series' => $series,
            ];
        }

        return [
            'top_division' => $topDivision,
            'items' => $items,
        ];
    }

    private function getStatusBreakdown(array $filters, User $user, int $totalDocs): array
    {
        $scope = $this->buildDocumentScope($filters, $user);

        $rawStatuses = (clone $scope)
            ->selectRaw('status, COUNT(DISTINCT id) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $categoryCounts = [
            'Completed' => 0,
            'In Progress' => 0,
            'Pending' => 0,
            'Returned' => 0,
            'Cancelled' => 0,
            'Archived' => 0,
            'Others' => 0,
        ];

        $colors = [
            'Completed' => '#4caf50',
            'In Progress' => '#2196f3',
            'Pending' => '#ff9800',
            'Returned' => '#9c27b0',
            'Cancelled' => '#f44336',
            'Archived' => '#795548',
            'Others' => '#607d8b',
        ];

        foreach ($rawStatuses as $status => $count) {
            $matchedCategory = 'Others';
            foreach (self::STATUS_CATEGORIES as $category => $aliases) {
                if (in_array(strtolower((string) $status), array_map('strtolower', $aliases), true)) {
                    $matchedCategory = $category;
                    break;
                }
            }
            $categoryCounts[$matchedCategory] += (int) $count;
        }

        $items = [];
        foreach ($categoryCounts as $category => $count) {
            $items[] = [
                'status' => $category,
                'count' => $count,
                'percentage' => $totalDocs > 0 ? round(($count / $totalDocs) * 100, 2) : 0,
                'color' => $colors[$category] ?? '#607d8b',
            ];
        }

        return $items;
    }

    private function getSentDocuments(array $filters, User $user): array
    {
        $scope = $this->buildDocumentScope($filters, $user);

        $page = request()->query('page', 1);
        $perPage = request()->query('per_page', 10);

        $paginator = (clone $scope)
            ->latest('created_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $data = collect($paginator->items())->map(function (Document $doc) {
            return [
                'id' => $doc->id,
                'tracking_id' => $doc->tracking_number,
                'title' => $doc->description ?? $doc->document_type ?? 'Untitled Document',
                'office' => $doc->office_assignment ?? $doc->origin_sender ?? 'Unassigned Office',
                'division' => $doc->division_assignment ?? 'Unassigned Division',
                'priority' => strtoupper($doc->priority_level ?? 'NORMAL'),
                'status' => ucfirst($doc->status ?? 'Pending'),
                'sent_date' => $doc->created_at?->format('M d, Y') ?? 'N/A',
                'reference_no' => $doc->tracking_number,
                'type' => $doc->is_internal ? 'Internal' : 'External',
            ];
        })->toArray();

        return [
            'data' => $data,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'total_pages' => $paginator->lastPage(),
                'total_items' => $paginator->total(),
            ],
        ];
    }

    private function getOfficeDivisionOverview(array $filters, User $user): array
    {
        $scope = $this->buildDocumentScope($filters, $user);

        $officeCounts = (clone $scope)
            ->selectRaw("COALESCE(office_assignment, 'Unassigned Office') as name, COUNT(DISTINCT id) as count")
            ->groupBy(DB::raw("COALESCE(office_assignment, 'Unassigned Office')"))
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        $byOffice = $officeCounts->map(function ($row) {
            return [
                'name' => $row->name,
                'documents' => (int) $row->count,
                'change_percentage' => 10.5,
                'change_type' => 'increase',
            ];
        })->toArray();

        $divisionCounts = (clone $scope)
            ->selectRaw("COALESCE(division_assignment, 'Unassigned Division') as name, COUNT(DISTINCT id) as count")
            ->groupBy(DB::raw("COALESCE(division_assignment, 'Unassigned Division')"))
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        $byDivision = $divisionCounts->map(function ($row) {
            return [
                'name' => $row->name,
                'documents' => (int) $row->count,
                'change_percentage' => 8.2,
                'change_type' => 'increase',
            ];
        })->toArray();

        return [
            'by_office' => $byOffice,
            'by_division' => $byDivision,
        ];
    }

    private function getOfficeSummaryCards(array $filters, User $user): array
    {
        $scope = $this->buildDocumentScope($filters, $user);

        $officeCounts = (clone $scope)
            ->selectRaw("COALESCE(office_assignment, 'Unassigned Office') as name, COUNT(DISTINCT id) as count")
            ->groupBy(DB::raw("COALESCE(office_assignment, 'Unassigned Office')"))
            ->orderByDesc('count')
            ->get();

        return $officeCounts->map(function ($row) {
            return [
                'name' => $row->name,
                'documents' => (int) $row->count,
                'change_percentage' => 12.4,
                'change_type' => 'increase',
            ];
        })->toArray();
    }

    private function calculateComparison(int $current, int $previous, int $daysDiff): array
    {
        if ($previous === 0 && $current > 0) {
            return [
                'percentage' => null,
                'type' => 'new',
                'label' => 'New this period',
            ];
        }

        if ($previous === 0 && $current === 0) {
            return [
                'percentage' => null,
                'type' => 'no_change',
                'label' => 'No change',
            ];
        }

        $percentage = round((($current - $previous) / $previous) * 100, 1);
        $type = $percentage >= 0 ? 'increase' : 'decrease';
        $label = ($percentage >= 0 ? "▲ {$percentage}%" : "▼ ".abs($percentage)."%")." vs previous {$daysDiff} days";

        return [
            'percentage' => abs($percentage),
            'type' => $type,
            'label' => $label,
        ];
    }

    private function generateDailyTrend(Builder $scope, array $dates): array
    {
        $counts = [];
        foreach ($dates as $dateStr) {
            $dayStartUtc = Carbon::parse($dateStr, 'Asia/Manila')->startOfDay()->setTimezone('UTC');
            $dayEndUtc = Carbon::parse($dateStr, 'Asia/Manila')->addDay()->startOfDay()->setTimezone('UTC');

            $counts[] = (clone $scope)
                ->where('created_at', '>=', $dayStartUtc)
                ->where('created_at', '<', $dayEndUtc)
                ->distinct('documents.id')
                ->count('documents.id');
        }

        return $counts;
    }

    private function generateDailySeries(Builder $scope, array $dates): array
    {
        $series = [];
        foreach ($dates as $dateStr) {
            $dayStartUtc = Carbon::parse($dateStr, 'Asia/Manila')->startOfDay()->setTimezone('UTC');
            $dayEndUtc = Carbon::parse($dateStr, 'Asia/Manila')->addDay()->startOfDay()->setTimezone('UTC');

            $count = (clone $scope)
                ->where('created_at', '>=', $dayStartUtc)
                ->where('created_at', '<', $dayEndUtc)
                ->distinct('documents.id')
                ->count('documents.id');

            $series[] = [
                'date' => $dateStr,
                'count' => $count,
            ];
        }

        return $series;
    }

    private function getAvailableOffices(User $user): array
    {
        if ($user->hasRole('admin')) {
            return Office::where('is_active', true)->select('id', 'name', 'code')->get()->toArray();
        }

        if ($user->hasRole('supervisor') && $user->office_id) {
            return Office::where('id', $user->office_id)->select('id', 'name', 'code')->get()->toArray();
        }

        return [];
    }

    private function getAvailableDivisions(User $user): array
    {
        if ($user->hasRole('admin')) {
            return Division::where('is_active', true)->select('id', 'name', 'code', 'office_id')->get()->toArray();
        }

        if ($user->hasRole('supervisor') && $user->office_id) {
            return Division::where('office_id', $user->office_id)->where('is_active', true)->select('id', 'name', 'code', 'office_id')->get()->toArray();
        }

        return [];
    }

    public function getOfficesReport(array $filters, User $user): array
    {
        $scope = $this->buildDocumentScope($filters, $user);

        $query = (clone $scope)
            ->selectRaw("
                COALESCE(office_assignment, 'Unassigned Office') as office_name,
                COUNT(DISTINCT id) as documents,
                MAX(created_at) as last_activity
            ")
            ->groupBy(DB::raw("COALESCE(office_assignment, 'Unassigned Office')"))
            ->havingRaw("COUNT(DISTINCT id) > 0");

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->havingRaw("COALESCE(office_assignment, 'Unassigned Office') LIKE ?", ["%{$search}%"]);
        }

        $allRows = $query->get();

        $items = [];
        foreach ($allRows as $row) {
            $officeName = $row->office_name;
            if (empty(trim($officeName))) {
                $officeName = 'Unassigned Office';
            }

            $officeModel = $officeName !== 'Unassigned Office' ? Office::where('name', $officeName)->first() : null;

            $officeScope = (clone $scope)->where(function ($q) use ($officeName) {
                if ($officeName === 'Unassigned Office') {
                    $q->whereNull('office_assignment')
                      ->orWhere('office_assignment', '')
                      ->orWhere('office_assignment', 'Unassigned Office');
                } else {
                    $q->where('office_assignment', $officeName);
                }
            });

            $docs = $officeScope->get();

            $completed = 0;
            $inProgress = 0;
            $pending = 0;
            $returned = 0;
            $cancelled = 0;
            $archived = 0;
            $others = 0;

            foreach ($docs as $doc) {
                $matchedCategory = 'Others';
                foreach (self::STATUS_CATEGORIES as $category => $aliases) {
                    if (in_array(strtolower((string) $doc->status), array_map('strtolower', $aliases), true)) {
                        $matchedCategory = $category;
                        break;
                    }
                }
                if ($matchedCategory === 'Completed') $completed++;
                elseif ($matchedCategory === 'In Progress') $inProgress++;
                elseif ($matchedCategory === 'Pending') $pending++;
                elseif ($matchedCategory === 'Returned') $returned++;
                elseif ($matchedCategory === 'Cancelled') $cancelled++;
                elseif ($matchedCategory === 'Archived') $archived++;
                else $others++;
            }

            $isUnassigned = $officeName === 'Unassigned Office';

            $items[] = [
                'key' => 'office:' . $officeName,
                'office_id' => $officeModel?->id,
                'office_name' => $officeName,
                'office_assignment' => $isUnassigned ? '__unassigned__' : $officeName,
                'is_unassigned' => $isUnassigned,
                'is_legacy' => ! $isUnassigned && ! $officeModel,
                'documents' => (int) $row->documents,
                'total_documents' => (int) $row->documents,
                'completed' => $completed,
                'in_progress' => $inProgress,
                'pending' => $pending,
                'returned' => $returned,
                'cancelled' => $cancelled,
                'archived' => $archived,
                'others' => $others,
                'last_activity' => $row->last_activity ? Carbon::parse($row->last_activity)->toIso8601String() : null,
            ];
        }

        $sort = $filters['sort'] ?? 'documents';
        $direction = strtolower($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        usort($items, function ($a, $b) use ($sort, $direction) {
            $valA = $a[$sort] ?? $a['documents'];
            $valB = $b[$sort] ?? $b['documents'];

            if ($valA == $valB) {
                return 0;
            }

            if ($direction === 'asc') {
                return $valA < $valB ? -1 : 1;
            }

            return $valA > $valB ? -1 : 1;
        });

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, (int) ($filters['per_page'] ?? 20));
        $total = count($items);
        $pagedData = array_slice($items, ($page - 1) * $perPage, $perPage);

        $sumDocuments = array_sum(array_column($items, 'documents'));
        $universeDocuments = (clone $scope)->distinct('id')->count('id');

        return [
            'data' => array_values($pagedData),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_documents' => $sumDocuments,
                'universe_documents' => $universeDocuments,
                'reconciled' => ($sumDocuments === $universeDocuments),
            ],
        ];
    }

    public function getDivisionsReport(array $filters, User $user): array
    {
        $scope = $this->buildDocumentScope($filters, $user);

        $query = (clone $scope)
            ->selectRaw("
                COALESCE(division_assignment, 'Unassigned Division') as division_name,
                COALESCE(office_assignment, 'Unassigned Office') as office_name,
                COUNT(DISTINCT id) as documents,
                MAX(created_at) as last_activity
            ")
            ->groupBy(
                DB::raw("COALESCE(division_assignment, 'Unassigned Division')"),
                DB::raw("COALESCE(office_assignment, 'Unassigned Office')")
            )
            ->havingRaw("COUNT(DISTINCT id) > 0");

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->havingRaw("COALESCE(division_assignment, 'Unassigned Division') LIKE ?", ["%{$search}%"]);
        }

        $allRows = $query->get();

        $items = [];
        foreach ($allRows as $row) {
            $divisionName = $row->division_name;
            if (empty(trim($divisionName))) {
                $divisionName = 'Unassigned Division';
            }

            $officeName = $row->office_name;
            if (empty(trim($officeName))) {
                $officeName = 'Unassigned Office';
            }

            $divisionModel = $divisionName !== 'Unassigned Division' ? Division::where('name', $divisionName)->first() : null;
            $officeModel = $officeName !== 'Unassigned Office' ? Office::where('name', $officeName)->first() : null;

            $divisionScope = (clone $scope)->where(function ($q) use ($divisionName, $officeName) {
                if ($divisionName === 'Unassigned Division') {
                    $q->where(function ($sub) {
                        $sub->whereNull('division_assignment')
                            ->orWhere('division_assignment', '')
                            ->orWhere('division_assignment', 'Unassigned Division');
                    });
                } else {
                    $q->where('division_assignment', $divisionName);
                }

                if ($officeName === 'Unassigned Office') {
                    $q->where(function ($sub) {
                        $sub->whereNull('office_assignment')
                            ->orWhere('office_assignment', '')
                            ->orWhere('office_assignment', 'Unassigned Office');
                    });
                } else {
                    $q->where('office_assignment', $officeName);
                }
            });

            $docs = $divisionScope->get();

            $completed = 0;
            $inProgress = 0;
            $pending = 0;
            $returned = 0;
            $cancelled = 0;
            $archived = 0;
            $others = 0;

            foreach ($docs as $doc) {
                $matchedCategory = 'Others';
                foreach (self::STATUS_CATEGORIES as $category => $aliases) {
                    if (in_array(strtolower((string) $doc->status), array_map('strtolower', $aliases), true)) {
                        $matchedCategory = $category;
                        break;
                    }
                }
                if ($matchedCategory === 'Completed') $completed++;
                elseif ($matchedCategory === 'In Progress') $inProgress++;
                elseif ($matchedCategory === 'Pending') $pending++;
                elseif ($matchedCategory === 'Returned') $returned++;
                elseif ($matchedCategory === 'Cancelled') $cancelled++;
                elseif ($matchedCategory === 'Archived') $archived++;
                else $others++;
            }

            $isUnassignedDivision = $divisionName === 'Unassigned Division';
            $isUnassignedOffice = $officeName === 'Unassigned Office';

            $items[] = [
                'key' => 'division:' . $officeName . ':' . $divisionName,
                'division_id' => $divisionModel?->id,
                'division_name' => $divisionName,
                'division_assignment' => $isUnassignedDivision ? '__unassigned__' : $divisionName,
                'office_id' => $officeModel?->id,
                'office_name' => $officeName,
                'office_assignment' => $isUnassignedOffice ? '__unassigned__' : $officeName,
                'is_unassigned' => $isUnassignedDivision,
                'is_legacy' => ! $isUnassignedDivision && ! $divisionModel,
                'documents' => (int) $row->documents,
                'total_documents' => (int) $row->documents,
                'completed' => $completed,
                'in_progress' => $inProgress,
                'pending' => $pending,
                'returned' => $returned,
                'cancelled' => $cancelled,
                'archived' => $archived,
                'others' => $others,
                'last_activity' => $row->last_activity ? Carbon::parse($row->last_activity)->toIso8601String() : null,
            ];
        }

        $sort = $filters['sort'] ?? 'documents';
        $direction = strtolower($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        usort($items, function ($a, $b) use ($sort, $direction) {
            $valA = $a[$sort] ?? $a['documents'];
            $valB = $b[$sort] ?? $b['documents'];

            if ($valA == $valB) {
                return 0;
            }

            if ($direction === 'asc') {
                return $valA < $valB ? -1 : 1;
            }

            return $valA > $valB ? -1 : 1;
        });

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, (int) ($filters['per_page'] ?? 20));
        $total = count($items);
        $pagedData = array_slice($items, ($page - 1) * $perPage, $perPage);

        $sumDocuments = array_sum(array_column($items, 'documents'));
        $universeDocuments = (clone $scope)->distinct('id')->count('id');

        return [
            'data' => array_values($pagedData),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_documents' => $sumDocuments,
                'universe_documents' => $universeDocuments,
                'reconciled' => ($sumDocuments === $universeDocuments),
            ],
        ];
    }
}
