<?php

namespace App\Console\Commands;

use App\Services\PmisEmployeeService;
use Illuminate\Console\Command;
use Throwable;

class PreviewPmisEmployees extends Command
{
    protected $signature = 'pmis:preview-employees {--limit=10 : Maximum employees to display, capped at 10}';

    protected $description = 'Preview active PMIS employees from dbo.vwActive without modifying PMIS or local data';

    public function handle(PmisEmployeeService $pmisEmployees): int
    {
        $limit = min(max(1, (int) $this->option('limit')), 10);

        $this->info('====================================================');
        $this->info(' PMIS Active Employee Preview');
        $this->info('====================================================');
        $this->line('Source: dbo.vwActive');
        $this->line('Mode: read-only SELECT via DB::connection(\'pmis\')');
        $this->line('Query: ' . $pmisEmployees->previewQueryDescription($limit));
        $this->line('----------------------------------------------------');

        try {
            $employees = $pmisEmployees->previewRows($limit);

            $this->table(
                ['PMISNo', 'Full Name', 'Birth Date', 'Designation', 'DesigCode', 'Office', 'Status'],
                $employees->map(function ($employee) use ($pmisEmployees) {
                    $birthDate = data_get($employee, 'BirthDate');
                    $formattedBirthDate = filled($birthDate)
                        ? \Illuminate\Support\Carbon::parse($birthDate)->format('Y-m-d')
                        : 'none';

                    return [
                        data_get($employee, 'PMISNo'),
                        $pmisEmployees->fullName($employee),
                        $formattedBirthDate,
                        data_get($employee, 'Designation'),
                        data_get($employee, 'DesigCode'),
                        data_get($employee, 'Office'),
                        data_get($employee, 'Status'),
                    ];
                })->all()
            );

            $idValues = $employees->pluck('PMISNo');
            $nullIdCount = $idValues->filter(fn ($value) => blank($value))->count();
            $duplicateIds = $idValues
                ->filter(fn ($value) => filled($value))
                ->map(fn ($value) => trim((string) $value))
                ->countBy()
                ->filter(fn ($count) => $count > 1)
                ->keys()
                ->values();

            $this->newLine();
            $this->info('Preview validation');
            $this->line('Rows shown: ' . $employees->count());
            $this->line('Null/blank PMISNo values encountered: ' . $nullIdCount);
            $this->line('Duplicate PMISNo values encountered in preview: ' . ($duplicateIds->isEmpty() ? 'none' : $duplicateIds->implode(', ')));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Failed to preview PMIS employees.');
            $this->error('Error details: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}
