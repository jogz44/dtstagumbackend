<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Carbon;

class PerformanceCalculationService
{
    /**
     * @return array{documents_completed:int,average_processing_seconds:float|null,on_time_rate:float|null,overdue_completed_count:int,productivity_completed_count:int}
     */
    public function forUser(string $userId, Carbon $periodStart, Carbon $periodEnd): array
    {
        $documents = Document::query()
            ->where('completed_by_user_id', $userId)
            ->whereNotNull('completed_at')
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$periodStart, $periodEnd])
            ->get(['received_at', 'due_at', 'completed_at']);

        return $this->metricsFromDocuments($documents);
    }

    /**
     * @return array{documents_completed:int,average_processing_seconds:float|null,on_time_rate:float|null,overdue_completed_count:int,productivity_completed_count:int}
     */
    public function forAll(Carbon $periodStart, Carbon $periodEnd, ?string $officeId = null): array
    {
        $query = Document::query()
            ->whereNotNull('completed_by_user_id')
            ->whereNotNull('completed_at')
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$periodStart, $periodEnd]);

        if ($officeId) {
            $query->where(function ($q) use ($officeId) {
                $q->whereHas('completedBy', fn ($qUser) => $qUser->where('office_id', $officeId))
                  ->orWhereHas('creator', fn ($qCreator) => $qCreator->where('office_id', $officeId));
            });
        }

        $documents = $query->get(['received_at', 'due_at', 'completed_at']);

        return $this->metricsFromDocuments($documents);
    }

    /**
     * @param  iterable<int, Document>  $documents
     * @return array{documents_completed:int,average_processing_seconds:float|null,on_time_rate:float|null,overdue_completed_count:int,productivity_completed_count:int}
     */
    private function metricsFromDocuments(iterable $documents): array
    {
        $completedCount = 0;
        $processingSeconds = [];
        $deadlineCount = 0;
        $onTimeCount = 0;
        $overdueCount = 0;

        foreach ($documents as $document) {
            $completedCount++;

            if ($document->received_at !== null && $document->completed_at !== null) {
                $processingSeconds[] = $document->received_at->diffInSeconds($document->completed_at);
            }

            if ($document->due_at !== null && $document->completed_at !== null) {
                $deadlineCount++;
                if ($document->completed_at->lessThanOrEqualTo($document->due_at)) {
                    $onTimeCount++;
                } else {
                    $overdueCount++;
                }
            }
        }

        return [
            'documents_completed' => $completedCount,
            'average_processing_seconds' => $processingSeconds === [] ? null : array_sum($processingSeconds) / count($processingSeconds),
            'on_time_rate' => $deadlineCount === 0 ? null : ($onTimeCount / $deadlineCount) * 100,
            'overdue_completed_count' => $overdueCount,
            'productivity_completed_count' => $completedCount,
        ];
    }
}
