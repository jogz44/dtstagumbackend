<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\DocumentHistory;
use App\Support\DocumentStatuses;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class BackfillDocumentAnalyticsTimestamps extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'documents:backfill-analytics-timestamps';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Conservatively backfill missing received_at and completed_at timestamps on documents';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting conservative analytics timestamp backfill...');

        $scanned = 0;
        $receivedPopulated = 0;
        $completedPopulated = 0;
        $ambiguous = 0;
        $skipped = 0;

        Document::with('histories')->chunk(100, function ($documents) use (&$scanned, &$receivedPopulated, &$completedPopulated, &$ambiguous, &$skipped) {
            foreach ($documents as $document) {
                $scanned++;
                $wasUpdated = false;

                // 1. Backfill received_at if null
                if (is_null($document->received_at)) {
                    $receivedAt = $this->resolveReceivedAt($document);
                    if ($receivedAt) {
                        $document->received_at = $receivedAt;
                        $receivedPopulated++;
                        $wasUpdated = true;
                    } else {
                        $ambiguous++;
                    }
                } else {
                    $skipped++;
                }

                // 2. Backfill completed_at if null
                if (is_null($document->completed_at)) {
                    $completedAt = $this->resolveCompletedAt($document);
                    if ($completedAt) {
                        $document->completed_at = $completedAt;
                        $completedPopulated++;
                        $wasUpdated = true;
                    }
                }

                if ($wasUpdated) {
                    $document->save();
                }
            }
        });

        $this->newLine();
        $this->info('Timestamp backfill completed successfully:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Documents scanned', $scanned],
                ['received_at populated', $receivedPopulated],
                ['completed_at populated', $completedPopulated],
                ['Ambiguous / unresolved', $ambiguous],
                ['Skipped / already set', $skipped],
            ]
        );

        return Command::SUCCESS;
    }

    private function resolveReceivedAt(Document $document): ?Carbon
    {
        // 1. Check earliest history event where status_after or action is RECEIVED / PENDING
        $receivedHistory = $document->histories
            ->filter(function (DocumentHistory $h) {
                $action = strtoupper((string) $h->action);
                $statusAfter = strtolower((string) $h->status_after);

                return $action === 'RECEIVED'
                    || in_array($statusAfter, ['received', 'pending', 'in_process', 'in progress'], true);
            })
            ->sortBy('created_at')
            ->first();

        if ($receivedHistory && $receivedHistory->created_at) {
            return $receivedHistory->created_at;
        }

        // 2. Existing reliable incoming reception timestamp if self-assigned or internal registration
        if ($document->self_assign || $document->is_internal || strtolower((string) $document->status) !== 'outgoing') {
            return $document->created_at;
        }

        // 3. Otherwise leave received_at = null for manual review
        return null;
    }

    private function resolveCompletedAt(Document $document): ?Carbon
    {
        $normalizedStatus = strtolower((string) $document->status);
        $completedStatuses = ['completed', 'released', 'approved'];

        // Only derive completed_at if document is currently in or was previously in a completed state
        $completedHistory = $document->histories
            ->filter(function (DocumentHistory $h) use ($completedStatuses) {
                $action = strtoupper((string) $h->action);
                $statusAfter = strtolower((string) $h->status_after);

                return $action === 'COMPLETED'
                    || in_array($statusAfter, $completedStatuses, true);
            })
            ->sortBy('created_at')
            ->first();

        if ($completedHistory && $completedHistory->created_at) {
            return $completedHistory->created_at;
        }

        if (in_array($normalizedStatus, $completedStatuses, true)) {
            return $document->updated_at ?? $document->created_at;
        }

        return null;
    }
}
