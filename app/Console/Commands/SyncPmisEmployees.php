<?php

namespace App\Console\Commands;

use App\Models\PmisEmployee;
use App\Services\AuditService;
use App\Services\PmisEmployeeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SyncPmisEmployees extends Command
{
    protected $signature = 'pmis:sync-employees
        {--dry-run : Preview local employee changes without writing to MySQL}
        {--commit : Apply local MySQL creates and updates}';

    protected $description = 'Synchronize PMIS employees from dbo.vwActive into local MySQL with dry-run safety';

    private const REQUIRED_LOCAL_COLUMNS = [
        'id',
        'pmis_no',
        'first_name',
        'middle_name',
        'last_name',
        'birth_date',
        'designation',
        'designation_code',
        'office',
        'employment_status',
        'telephone',
        'pmis_synced_at',
        'created_at',
        'updated_at',
    ];

    private const COMPARED_FIELDS = [
        'first_name',
        'middle_name',
        'last_name',
        'birth_date',
        'designation',
        'designation_code',
        'office',
        'employment_status',
        'telephone',
    ];

    public function handle(PmisEmployeeService $pmisEmployees): int
    {
        $commit = (bool) $this->option('commit');

        if ($commit && $this->option('dry-run')) {
            $this->error('Choose either --dry-run or --commit, not both.');

            return self::FAILURE;
        }

        $this->info('====================================================');
        $this->info($commit ? ' PMIS Employee Sync Commit' : ' PMIS Employee Sync Dry Run');
        $this->info('====================================================');
        $this->line('PMIS source: dbo.vwActive');
        $this->line('PMIS mode: read-only SELECT via DB::connection(\'pmis\')');
        $this->line('Local mode: ' . ($commit ? 'commit local MySQL creates/updates only' : 'dry-run only, no MySQL writes'));
        $this->line('Query: ' . $pmisEmployees->syncQueryDescription());
        $this->line('----------------------------------------------------');

        try {
            $pmisRows = $pmisEmployees->getActiveEmployees();
            $localTableExists = Schema::connection('mysql')->hasTable('pmis_employees');

            if ($pmisRows->isEmpty()) {
                $this->error('PMIS returned 0 records. Synchronization aborted safely to prevent dataset wipe.');
                Log::warning('PMIS sync aborted: PMIS query returned 0 records.');

                return self::FAILURE;
            }

            $existingCount = $localTableExists ? PmisEmployee::query()->count() : 0;
            if ($existingCount > 100 && $pmisRows->count() < ($existingCount * 0.8)) {
                $this->error("PMIS returned {$pmisRows->count()} records, which is a significant drop (>20%) from existing {$existingCount} local records. Synchronization aborted safely.");
                Log::warning("PMIS sync aborted: dataset drop from {$existingCount} to {$pmisRows->count()}.");

                return self::FAILURE;
            }

            if ($commit) {
                if (! $localTableExists) {
                    $this->error('Local table pmis_employees does not exist. Run the migration before --commit.');

                    return self::FAILURE;
                }

                $missingColumns = $this->missingLocalColumns();
                if ($missingColumns !== []) {
                    $this->error('Local table pmis_employees is missing required columns: ' . implode(', ', $missingColumns));

                    return self::FAILURE;
                }
            }

            $localByPmisNo = $localTableExists
                ? PmisEmployee::query()
                    ->whereIn('pmis_no', $pmisRows
                        ->pluck('PMISNo')
                        ->filter(fn ($value) => filled($value))
                        ->map(fn ($value) => trim((string) $value))
                        ->unique()
                        ->values())
                    ->get()
                    ->keyBy('pmis_no')
                : collect();

            $summary = [
                'read' => $pmisRows->count(),
                'created' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'missing_blank_pmis_no' => 0,
                'failures' => 0,
            ];

            $duplicatePmisNos = $pmisRows
                ->pluck('PMISNo')
                ->filter(fn ($value) => filled($value))
                ->map(fn ($value) => trim((string) $value))
                ->countBy()
                ->filter(fn ($count) => $count > 1);

            $examples = [
                'create' => [],
                'update' => [],
                'invalid' => [],
                'failure' => [],
            ];

            $plannedCreates = [];
            $plannedUpdates = [];
            $seenPmisNos = [];

            foreach ($pmisRows as $pmisRow) {
                $attributes = $pmisEmployees->toLocalAttributes($pmisRow);
                $pmisNo = $attributes['pmis_no'];

                if (blank($pmisNo)) {
                    $summary['missing_blank_pmis_no']++;
                    $this->pushExample($examples['invalid'], '(blank PMISNo)');
                    continue;
                }

                if (isset($seenPmisNos[$pmisNo])) {
                    $summary['failures']++;
                    $this->pushExample($examples['failure'], "duplicate {$pmisNo}");
                    continue;
                }

                $seenPmisNos[$pmisNo] = true;
                $localEmployee = $localByPmisNo->get($pmisNo);

                if (! $localEmployee) {
                    $summary['created']++;
                    $plannedCreates[] = $attributes;
                    $this->pushExample($examples['create'], $pmisNo);
                    continue;
                }

                if ($this->hasChanges($localEmployee, $attributes)) {
                    $summary['updated']++;
                    $plannedUpdates[] = [$localEmployee, $attributes];
                    $this->pushExample($examples['update'], $pmisNo);
                    continue;
                }

                $summary['unchanged']++;
            }

            if (! $localTableExists) {
                $this->warn('Local table pmis_employees does not exist yet. Dry-run treats valid PMIS rows as would-create.');
            }

            if ($commit) {
                $this->warn('COMMIT MODE: this will write to local MySQL table pmis_employees only.');
                $this->warn('PMIS remains read-only. No users are modified. No deletes or inactive marking will run.');
                $this->line('----------------------------------------------------');

                DB::connection('mysql')->transaction(function () use ($plannedCreates, $plannedUpdates) {
                    $syncedAt = now();

                    foreach ($plannedCreates as $attributes) {
                        PmisEmployee::query()->create([
                            ...$attributes,
                            'pmis_synced_at' => $syncedAt,
                        ]);
                    }

                    foreach ($plannedUpdates as [$localEmployee, $attributes]) {
                        $localEmployee->fill([
                            ...$attributes,
                            'pmis_synced_at' => $syncedAt,
                        ])->save();
                    }
                });
            }

            $this->table(
                ['Metric', 'Count'],
                [
                    ['Records read from PMIS', $summary['read']],
                    [$commit ? 'Records created' : 'Records that would be created', $summary['created']],
                    [$commit ? 'Records updated' : 'Records that would be updated', $summary['updated']],
                    ['Unchanged records', $summary['unchanged']],
                    ['Records with missing/blank PMISNo', $summary['missing_blank_pmis_no']],
                    ['Duplicate PMISNo values in PMIS read', $duplicatePmisNos->count()],
                    ['Failures', $summary['failures']],
                ]
            );

            $this->newLine();
            $this->info('Examples');
            $this->line('Create: ' . ($examples['create'] ? implode(', ', $examples['create']) : 'none'));
            $this->line('Update: ' . ($examples['update'] ? implode(', ', $examples['update']) : 'none'));
            $this->line('Invalid: ' . ($examples['invalid'] ? implode(', ', $examples['invalid']) : 'none'));
            $this->line('Failure: ' . ($examples['failure'] ? implode(', ', $examples['failure']) : 'none'));

            if ($duplicatePmisNos->isNotEmpty()) {
                $this->line('Duplicate PMISNo values: ' . $duplicatePmisNos->keys()->take(10)->implode(', '));
            }

            if ($commit) {
                Log::info("PMIS employee sync completed. Read={$summary['read']}, Created={$summary['created']}, Updated={$summary['updated']}, Unchanged={$summary['unchanged']}, Failures={$summary['failures']}.");

                app(AuditService::class)->activity(
                    null,
                    'pmis.sync',
                    'PMIS',
                    "PMIS employee sync completed: {$summary['read']} read, {$summary['created']} created, {$summary['updated']} updated, {$summary['unchanged']} unchanged, {$summary['failures']} failures.",
                    null,
                    [
                        'outcome' => 'success',
                        'read' => $summary['read'],
                        'created' => $summary['created'],
                        'updated' => $summary['updated'],
                        'unchanged' => $summary['unchanged'],
                        'failures' => $summary['failures'],
                    ]
                );
            }

            $this->newLine();
            $this->comment($commit
                ? 'Commit sync complete. No PMIS data was modified. Users were not modified.'
                : 'Dry-run complete. No PMIS data was modified. No MySQL data was written.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('PMIS employee sync failed: ' . $e->getMessage(), ['exception' => $e]);

            app(AuditService::class)->activity(
                null,
                'pmis.sync',
                'PMIS',
                'PMIS employee sync failed: ' . $e->getMessage(),
                null,
                [
                    'outcome' => 'failure',
                    'error' => $e->getMessage(),
                ]
            );

            $this->error('Failed to sync PMIS employees.');
            $this->error('Error details: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    private function hasChanges(PmisEmployee $localEmployee, array $attributes): bool
    {
        foreach (self::COMPARED_FIELDS as $field) {
            if (($localEmployee->{$field} ?? null) !== ($attributes[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function pushExample(array &$examples, string $value): void
    {
        if (count($examples) < 5) {
            $examples[] = $value;
        }
    }

    private function missingLocalColumns(): array
    {
        return collect(self::REQUIRED_LOCAL_COLUMNS)
            ->reject(fn ($column) => Schema::connection('mysql')->hasColumn('pmis_employees', $column))
            ->values()
            ->all();
    }
}
