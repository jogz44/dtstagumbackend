<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class InspectPmisSchema extends Command
{
    protected $signature = 'pmis:inspect-schema {--limit=12 : Maximum relevant objects to show with column details}';

    protected $description = 'Inspect PMIS SQL Server schema metadata without reading or modifying PMIS data';

    private const KEYWORDS = [
        'employee',
        'employees',
        'personnel',
        'person',
        'staff',
        'emp',
        'idno',
        'employeeid',
        'employee_id',
        'department',
        'departments',
        'office',
        'offices',
        'division',
        'position',
        'positions',
        'job',
        'title',
        'status',
        'employment',
        'appointment',
        'plantilla',
        'designation',
    ];

    public function handle(): int
    {
        $this->info('====================================================');
        $this->info(' Inspecting PMIS Schema Metadata');
        $this->info('====================================================');

        try {
            $connection = DB::connection('pmis');
            $connection->getPdo();

            $database = $connection->selectOne('SELECT DB_NAME() AS name')->name ?? 'unknown';
            $this->line("Database: {$database}");
            $this->line('Mode: metadata-only SELECT queries');
            $this->line('----------------------------------------------------');

            $objects = collect($connection->select($this->objectsSql()));
            $columns = collect($connection->select($this->columnsSql()));

            $tables = $objects->where('object_type', 'TABLE')->values();
            $views = $objects->where('object_type', 'VIEW')->values();

            $this->info("User Tables ({$tables->count()})");
            $this->table(['Schema', 'Table'], $tables->map(fn ($object) => [
                $object->schema_name,
                $object->object_name,
            ])->all());

            $this->newLine();
            $this->info("Views ({$views->count()})");
            $this->table(['Schema', 'View'], $views->map(fn ($object) => [
                $object->schema_name,
                $object->object_name,
            ])->all());

            $columnGroups = $columns->groupBy('object_id');
            $candidates = $objects
                ->map(function ($object) use ($columnGroups) {
                    $objectColumns = $columnGroups->get($object->object_id, collect());
                    $haystack = strtolower($object->schema_name . ' ' . $object->object_name . ' ' . $objectColumns->pluck('column_name')->implode(' '));
                    $matchedKeywords = collect(self::KEYWORDS)
                        ->filter(fn ($keyword) => str_contains($haystack, $keyword))
                        ->values();

                    return [
                        'object' => $object,
                        'columns' => $objectColumns,
                        'score' => $matchedKeywords->count(),
                        'matches' => $matchedKeywords->implode(', '),
                    ];
                })
                ->filter(fn ($candidate) => $candidate['score'] > 0)
                ->sortByDesc('score')
                ->values();

            $this->newLine();
            $this->info('Likely Employee / Organization Objects');
            $this->table(
                ['Type', 'Schema', 'Name', 'Keyword Matches'],
                $candidates->map(fn ($candidate) => [
                    $candidate['object']->object_type,
                    $candidate['object']->schema_name,
                    $candidate['object']->object_name,
                    $candidate['matches'],
                ])->all()
            );

            $limit = max(1, (int) $this->option('limit'));

            $this->newLine();
            $this->info("Column Details For Top {$limit} Candidates");
            foreach ($candidates->take($limit) as $candidate) {
                $object = $candidate['object'];
                $this->line("{$object->object_type}: {$object->schema_name}.{$object->object_name}");
                $this->table(
                    ['Column', 'Data Type', 'Max Length', 'Precision', 'Scale', 'Nullable'],
                    $candidate['columns']->map(fn ($column) => [
                        $column->column_name,
                        $column->data_type,
                        $column->max_length,
                        $column->precision,
                        $column->scale,
                        $column->is_nullable ? 'yes' : 'no',
                    ])->all()
                );
            }

            $this->info('====================================================');
            $this->info(' PMIS Schema Metadata Inspection Complete');
            $this->info('====================================================');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Failed to inspect PMIS schema.');
            $this->error('Error details: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    private function objectsSql(): string
    {
        return <<<'SQL'
SELECT
    object_id,
    schema_name,
    object_name,
    object_type
FROM (
    SELECT
        t.object_id,
        s.name AS schema_name,
        t.name AS object_name,
        CAST('TABLE' AS varchar(10)) AS object_type
    FROM sys.tables t
    INNER JOIN sys.schemas s ON s.schema_id = t.schema_id
    WHERE t.is_ms_shipped = 0

    UNION ALL

    SELECT
        v.object_id,
        s.name AS schema_name,
        v.name AS object_name,
        CAST('VIEW' AS varchar(10)) AS object_type
    FROM sys.views v
    INNER JOIN sys.schemas s ON s.schema_id = v.schema_id
    WHERE v.is_ms_shipped = 0
) objects
ORDER BY object_type, schema_name, object_name
SQL;
    }

    private function columnsSql(): string
    {
        return <<<'SQL'
SELECT
    o.object_id,
    s.name AS schema_name,
    o.name AS object_name,
    c.column_id,
    c.name AS column_name,
    ty.name AS data_type,
    CASE
        WHEN ty.name IN ('nvarchar', 'nchar') AND c.max_length > 0 THEN c.max_length / 2
        ELSE c.max_length
    END AS max_length,
    c.precision,
    c.scale,
    c.is_nullable
FROM sys.objects o
INNER JOIN sys.schemas s ON s.schema_id = o.schema_id
INNER JOIN sys.columns c ON c.object_id = o.object_id
INNER JOIN sys.types ty ON ty.user_type_id = c.user_type_id
WHERE o.type IN ('U', 'V')
  AND o.is_ms_shipped = 0
ORDER BY s.name, o.name, c.column_id
SQL;
    }
}
