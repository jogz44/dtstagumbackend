<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class FindPmisEmployeeObject extends Command
{
    protected $signature = 'pmis:find-employee-object';

    protected $description = 'Find and validate the PMIS employee/personnel object using read-only metadata and TOP 5 queries';

    private const COLUMNS = [
        'PMISNo',
        'Surname',
        'Firstname',
        'Middlename',
        'Office',
        'Status',
        'Designation',
        'DesigCode',
        'TelNo',
    ];

    public function handle(): int
    {
        try {
            $connection = DB::connection('pmis');
            $connection->getPdo();

            $matches = collect($connection->select($this->matchingObjectsSql(), [
                ...self::COLUMNS,
                count(self::COLUMNS),
            ]));

            if ($matches->isEmpty()) {
                $this->warn('No table or view contains all required columns.');

                return self::SUCCESS;
            }

            $this->info('Objects containing all required columns');
            $this->table(['Schema', 'Object', 'Type'], $matches->map(fn ($match) => [
                $match->schema_name,
                $match->object_name,
                $match->object_type,
            ])->all());

            $target = $matches->first();
            $qualifiedName = $this->quoteName($target->schema_name) . '.' . $this->quoteName($target->object_name);

            $this->newLine();
            $this->info("TOP 5 read-only sample from {$target->schema_name}.{$target->object_name}");

            $sampleRows = $connection->select($this->sampleSql($qualifiedName));

            $this->table(self::COLUMNS, collect($sampleRows)->map(fn ($row) => array_map(
                fn ($column) => data_get((array) $row, $column),
                self::COLUMNS,
            ))->all());

            $pmisNoValues = collect($sampleRows)->pluck('PMISNo')->filter(fn ($value) => filled($value));
            $statusValues = collect($sampleRows)->pluck('Status')->filter(fn ($value) => filled($value))->unique()->values();
            $offices = collect($sampleRows)
                ->pluck('Office')
                ->filter(fn ($value) => filled($value))
                ->unique()
                ->values();

            $this->newLine();
            $this->info('Read-only validation summary');
            $this->line('Exact object: ' . $target->schema_name . '.' . $target->object_name . ' (' . $target->object_type . ')');
            $this->line('PMISNo present in TOP 5: ' . ($pmisNoValues->isNotEmpty() ? 'yes' : 'no'));
            $this->line('PMISNo unique within TOP 5: ' . ($pmisNoValues->count() === $pmisNoValues->unique()->count() ? 'yes' : 'no'));
            $this->line('Office values in TOP 5: ' . ($offices->isNotEmpty() ? $offices->implode('; ') : '(none in TOP 5)'));
            $this->line('Status values in TOP 5: ' . ($statusValues->isNotEmpty() ? $statusValues->implode(', ') : '(none in TOP 5)'));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Failed to validate PMIS employee object.');
            $this->error('Error details: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    private function matchingObjectsSql(): string
    {
        $placeholders = implode(',', array_fill(0, count(self::COLUMNS), '?'));

        return <<<SQL
SELECT
    s.name AS schema_name,
    o.name AS object_name,
    CASE o.type WHEN 'U' THEN 'TABLE' WHEN 'V' THEN 'VIEW' ELSE o.type END AS object_type
FROM sys.objects o
INNER JOIN sys.schemas s ON s.schema_id = o.schema_id
INNER JOIN sys.columns c ON c.object_id = o.object_id
WHERE o.type IN ('U', 'V')
  AND o.is_ms_shipped = 0
  AND c.name IN ({$placeholders})
GROUP BY s.name, o.name, o.type
HAVING COUNT(DISTINCT c.name) = ?
ORDER BY
    CASE o.type WHEN 'V' THEN 0 ELSE 1 END,
    s.name,
    o.name
SQL;
    }

    private function sampleSql(string $qualifiedName): string
    {
        $columns = collect(self::COLUMNS)
            ->map(fn ($column) => $this->quoteName($column))
            ->implode(', ');

        return "SELECT TOP 5 {$columns} FROM {$qualifiedName} ORDER BY {$this->quoteName('PMISNo')}";
    }

    private function quoteName(string $name): string
    {
        return '[' . str_replace(']', ']]', $name) . ']';
    }
}
