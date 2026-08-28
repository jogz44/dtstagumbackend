<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\User;
use App\Services\DocumentNotificationService;
use App\Support\DocumentStatuses;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SendDocumentDeadlineNotifications extends Command
{
    /** @var array<string, User>|null */
    private ?array $usersByNormalizedName = null;

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

        $thresholdConfig = config('documents.due_reminder_days', [3, 1]);
        $reminderDays = is_array($thresholdConfig)
            ? array_map('intval', $thresholdConfig)
            : array_map('intval', explode(',', (string) $thresholdConfig));

        $documentQuery = Document::query()
            ->whereNotNull('due_date')
            ->whereNotIn(DB::raw('LOWER(status)'), DocumentStatuses::TERMINAL)
            ->with([
                'acknowledgements' => fn ($query) => $query->latest(),
                'acknowledgements.user',
                'creator',
            ]);

        $processedCount = 0;
        $documentCount = 0;
        $today = now()->startOfDay();

        $documentQuery->chunkById(200, function ($documents) use (
            $notificationService,
            $reminderDays,
            $today,
            &$processedCount,
            &$documentCount,
        ) {
            $documentCount += $documents->count();

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
                } elseif ($daysUntilDue === 0) {
                    $notificationService->notifyDueToday($document, $responsibleUser);
                    $processedCount++;
                } elseif ($daysUntilDue < 0) {
                    $notificationService->notifyOverdue($document, $responsibleUser);
                    $processedCount++;
                }
            }
        });

        $this->info("Completed document deadline check. Processed {$processedCount} notifications for {$documentCount} documents.");

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

            $this->usersByNormalizedName ??= User::query()
                ->get()
                ->mapWithKeys(fn (User $user): array => [Str::lower(trim($user->full_name)) => $user])
                ->all();

            return $this->usersByNormalizedName[Str::lower(trim($assigned))] ?? $document->creator;
        }

        return $document->creator;
    }
}
