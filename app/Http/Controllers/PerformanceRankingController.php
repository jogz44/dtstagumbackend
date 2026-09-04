<?php

namespace App\Http\Controllers;

use App\Http\Requests\PerformanceQueryRequest;
use App\Models\Document;
use App\Models\User;
use App\Services\PerformanceCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class PerformanceRankingController extends Controller
{
    public function ranking(PerformanceQueryRequest $request, PerformanceCalculationService $calculations): JsonResponse
    {
        [$periodStart, $periodEnd] = $this->period($request);
        $metrics = $this->rankedUsers($request, $calculations, $periodStart, $periodEnd);
        $paginator = $this->paginate($metrics, $request);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'reporting_period' => $this->periodResponse($periodStart, $periodEnd),
            'unsupported_metrics' => ['attendance_rate', 'quality_score', 'accuracy', 'teamwork', 'overall_score', 'rating'],
        ]);
    }

    public function summary(PerformanceQueryRequest $request, PerformanceCalculationService $calculations): JsonResponse
    {
        [$periodStart, $periodEnd] = $this->period($request);
        $metrics = $this->rankedUsers($request, $calculations, $periodStart, $periodEnd);
        $topPerformer = $metrics->first();

        return response()->json([
            'data' => [
                'total_completed_documents' => ($allMetrics = $calculations->forAll($periodStart, $periodEnd))['documents_completed'],
                'average_processing_seconds' => $allMetrics['average_processing_seconds'],
                'overdue_completion_count' => $allMetrics['overdue_completed_count'],
                'top_performer' => $topPerformer,
                'top_performer_basis' => 'documents_completed, then average_processing_seconds',
            ],
            'reporting_period' => $this->periodResponse($periodStart, $periodEnd),
        ]);
    }

    public function profile(PerformanceQueryRequest $request, User $user, PerformanceCalculationService $calculations): JsonResponse
    {
        [$periodStart, $periodEnd] = $this->period($request);
        $metrics = $calculations->forUser($user->id, $periodStart, $periodEnd);

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'employee_id' => $user->employee_id,
                    'name' => $user->full_name,
                    'position' => $user->position,
                    'division' => $user->division?->name,
                ],
                ...$metrics,
                'attendance_rate' => null,
                'quality_score' => null,
                'accuracy' => null,
                'teamwork' => null,
                'overall_score' => null,
                'rating' => null,
            ],
            'reporting_period' => $this->periodResponse($periodStart, $periodEnd),
        ]);
    }

    public function completedDocuments(PerformanceQueryRequest $request, User $user): LengthAwarePaginator
    {
        [$periodStart, $periodEnd] = $this->period($request);
        $documents = Document::query()
            ->where('completed_by_user_id', $user->id)
            ->whereNotNull('completed_at')
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$periodStart, $periodEnd])
            ->latest('completed_at')
            ->paginate($request->integer('per_page', 15));

        $documents->through(function (Document $document): array {
            $processingSeconds = $document->received_at?->diffInSeconds($document->completed_at);
            $onTime = $document->due_at === null ? null : $document->completed_at->lessThanOrEqualTo($document->due_at);

            return [
                'tracking_number' => $document->tracking_number,
                'particular' => $document->description,
                'completed_at' => $document->completed_at?->toIso8601String(),
                'due_at' => $document->due_at?->toIso8601String(),
                'processing_seconds' => $processingSeconds,
                'on_time' => $onTime,
                'result' => $onTime === null ? null : ($onTime ? 'on_time' : 'late'),
            ];
        });

        return $documents;
    }

    private function period(PerformanceQueryRequest $request): array
    {
        $start = $request->filled('start_date')
            ? Carbon::parse($request->input('start_date'))->startOfDay()
            : now()->startOfMonth();
        $end = $request->filled('end_date')
            ? Carbon::parse($request->input('end_date'))->endOfDay()
            : now()->endOfDay();

        return [$start, $end];
    }

    private function rankedUsers(PerformanceQueryRequest $request, PerformanceCalculationService $calculations, Carbon $periodStart, Carbon $periodEnd)
    {
        $users = User::query()
            ->with('division')
            ->where('is_active', true)
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = '%'.strtolower((string) $request->input('search')).'%';
                $query->where(function ($query) use ($search): void {
                    $query->whereRaw('LOWER(first_name) LIKE ?', [$search])
                        ->orWhereRaw('LOWER(last_name) LIKE ?', [$search])
                        ->orWhereRaw('LOWER(employee_id) LIKE ?', [$search]);
                });
            })
            ->when($request->filled('division_id'), fn ($query) => $query->where('division_id', $request->input('division_id')))
            ->get();

        $ranked = $users->map(function (User $user) use ($calculations, $periodStart, $periodEnd): array {
            return [
                'user_id' => $user->id,
                'employee_id' => $user->employee_id,
                'staff_name' => $user->full_name,
                'position' => $user->position,
                'division' => $user->division?->name,
                ...$calculations->forUser($user->id, $periodStart, $periodEnd),
            ];
        })->filter(function (array $metrics) use ($request): bool {
            return ! $request->filled('minimum_on_time_rate')
                || ($metrics['on_time_rate'] !== null && $metrics['on_time_rate'] >= $request->float('minimum_on_time_rate'));
        });

        $sort = $request->input('sort', 'documents_completed');
        $direction = $request->input('direction', 'desc');

        return $ranked->sort(function (array $left, array $right) use ($sort, $direction): int {
            $leftValue = $left[$sort] ?? null;
            $rightValue = $right[$sort] ?? null;
            if ($leftValue === $rightValue) {
                return 0;
            }

            if ($leftValue === null) {
                return 1;
            }

            if ($rightValue === null) {
                return -1;
            }

            $comparison = $leftValue <=> $rightValue;

            return $direction === 'asc' ? $comparison : -$comparison;
        })->values();
    }

    private function paginate($items, PerformanceQueryRequest $request): LengthAwarePaginator
    {
        $perPage = $request->integer('per_page', 15);
        $page = $request->integer('page', 1);

        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }

    private function periodResponse(Carbon $periodStart, Carbon $periodEnd): array
    {
        return [
            'start' => $periodStart->toIso8601String(),
            'end' => $periodEnd->toIso8601String(),
        ];
    }
}
