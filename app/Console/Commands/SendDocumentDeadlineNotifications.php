<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\User;
use App\Services\DocumentNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SendDocumentDeadlineNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'documents:send-deadline-notifications';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send due-soon and overdue notifications to responsible users for actionable documents.';

    /**
     * Execute the console command.
     */
    public function handle(DocumentNotificationService $notificationService): int
    {
        $this->info('Starting document deadline notification checks...');

        $thresholdConfig = config('documents.due_reminder_days', env('DOCUMENT_DUE_REMINDER_DAYS', '3,1'));
        $reminderDays = is_array($thresholdConfig)
            ? array_map('intval', $thresholdConfig)
            : array_map('intval', explode(',', (string) $thresholdConfig));

        $documents = Document::query()
            ->whereNotNull('due_date')
            ->whereNotIn('status', DocumentNotificationService::NON_ACTIONABLE_STATUSES)
            ->with(['acknowledgements' => fn ($q) => $q->latest()])
            ->get();

        $processedCount = 0;
        $today = now()->startOfDay();

        foreach ($documents as $document) {
            $responsibleUser = $this->resolveResponsibleUser($document);

            if (! $responsibleUser) {
                continue;
            }

            $dueDate = $document->due_date->startOfDay();
            $daysUntilDue = (int) $today->diffInDays($dueDate, false);

            if ($daysUntilDue > 0 && in_array($daysUntilDue, $reminderDays, true)) {
                $notificationService->notifyDueSoon($document, $responsibleUser, $daysUntilDue);
                $processedCount++;
            } elseif ($daysUntilDue < 0) {
                $notificationService->notifyOverdue($document, $responsibleUser);
                $processedCount++;
            }
        }

        $this->info("Completed document deadline check. Processed {$processedCount} notifications for {$documents->count()} documents.");

        return Command::SUCCESS;
    }

    /**
     * Resolve the current responsible user for a document.
     */
    protected function resolveResponsibleUser(Document $document): ?User
    {
        $ack = $document->acknowledgements->first();
        if ($ack && $ack->user) {
            return $ack->user;
        }

        if (! empty($document->assigned_user)) {
            $assigned = $document->assigned_user;
            if (Str::isUuid($assigned)) {
                $user = User::find($assigned);
                if ($user) {
                    return $user;
                }
            }

            $normalized = strtolower(trim($assigned));
            $candidates = User::all();
            foreach ($candidates as $candidate) {
                if (strtolower(trim($candidate->full_name)) === $normalized) {
                    return $candidate;
                }
            }
        }

        return $document->creator;
    }
}
