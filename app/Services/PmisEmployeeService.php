<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PmisEmployeeService
{
    public const VIEW = 'dbo.vwActive';

    public const COLUMNS = [
        'PMISNo',
        'Surname',
        'Firstname',
        'Middlename',
        'BirthDate',
        'Office',
        'Status',
        'Designation',
        'DesigCode',
        'TelNo',
    ];

    public function getActiveEmployees(?int $limit = null): Collection
    {
        $query = $this->baseQuery()
            ->orderBy('Surname')
            ->orderBy('Firstname');

        if ($limit !== null) {
            $query->limit(max(1, $limit));
        }

        return $query->get();
    }

    public function findByPmisNo(string $pmisNo): ?object
    {
        return $this->baseQuery()
            ->where('PMISNo', $pmisNo)
            ->first();
    }

    public function previewRows(int $limit = 10): Collection
    {
        return $this->getActiveEmployees(min(max(1, $limit), 10));
    }

    public function toLocalAttributes(object $employee): array
    {
        return [
            'pmis_no' => $this->normalize(data_get($employee, 'PMISNo')),
            'first_name' => $this->normalize(data_get($employee, 'Firstname')),
            'middle_name' => $this->normalize(data_get($employee, 'Middlename')),
            'last_name' => $this->normalize(data_get($employee, 'Surname')),
            'birth_date' => $this->normalizeDate(data_get($employee, 'BirthDate')),
            'designation' => $this->normalize(data_get($employee, 'Designation')),
            'designation_code' => $this->normalize(data_get($employee, 'DesigCode')),
            'office' => $this->normalize(data_get($employee, 'Office')),
            'employment_status' => $this->normalize(data_get($employee, 'Status')),
            'telephone' => $this->normalize(data_get($employee, 'TelNo')),
        ];
    }

    public function fullName(object $employee): string
    {
        return collect([
            data_get($employee, 'Firstname'),
            data_get($employee, 'Middlename'),
            data_get($employee, 'Surname'),
        ])
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => trim((string) $value))
            ->implode(' ');
    }

    public function previewQueryDescription(int $limit = 10): string
    {
        $columns = collect(self::COLUMNS)
            ->map(fn ($column) => '[' . $column . ']')
            ->implode(', ');

        return 'SELECT TOP ' . min(max(1, $limit), 10) . " {$columns} FROM [dbo].[vwActive] ORDER BY [Surname], [Firstname]";
    }

    public function syncQueryDescription(): string
    {
        $columns = collect(self::COLUMNS)
            ->map(fn ($column) => '[' . $column . ']')
            ->implode(', ');

        return "SELECT {$columns} FROM [dbo].[vwActive] ORDER BY [Surname], [Firstname]";
    }

    private function baseQuery(): Builder
    {
        return DB::connection('pmis')
            ->table(self::VIEW)
            ->select(self::COLUMNS);
    }

    private function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalizeDate(mixed $value): ?string
    {
        $raw = $this->normalize($value);
        if ($raw === null) {
            return null;
        }

        try {
            $date = \Illuminate\Support\Carbon::parse($raw);
            return $date->year > 1900 ? $date->format('Y-m-d') : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
