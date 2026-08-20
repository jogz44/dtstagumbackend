<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PmisEmployeeService
{
    public const SOURCE = 'dbo.xPersonal';

    public function getActiveEmployees(?int $limit = null): Collection
    {
        $topClause = $limit !== null ? 'TOP ' . max(1, $limit) : '';

        $sql = "
            WITH LatestService AS (
                SELECT ControlNo, Designation, DesigCode, Office, Divisions, Status,
                       ROW_NUMBER() OVER (PARTITION BY ControlNo ORDER BY FromDate DESC, ToDate DESC) as rn
                FROM dbo.xService
            )
            SELECT {$topClause}
                p.ControlNo AS PMISNo,
                p.PMID,
                p.Surname,
                p.Firstname,
                p.MIddlename,
                p.BirthDate,
                p.TelNo,
                s.Designation,
                s.DesigCode,
                s.Office,
                s.Divisions,
                s.Status
            FROM dbo.xPersonal p
            LEFT JOIN LatestService s ON p.ControlNo = s.ControlNo AND s.rn = 1
            ORDER BY p.Surname, p.Firstname
        ";

        return collect(DB::connection('pmis')->select($sql));
    }

    public function findByPmisNo(string $pmisNo): ?object
    {
        $sql = "
            WITH LatestService AS (
                SELECT ControlNo, Designation, DesigCode, Office, Divisions, Status,
                       ROW_NUMBER() OVER (PARTITION BY ControlNo ORDER BY FromDate DESC, ToDate DESC) as rn
                FROM dbo.xService
            )
            SELECT TOP 1
                p.ControlNo AS PMISNo,
                p.PMID,
                p.Surname,
                p.Firstname,
                p.MIddlename,
                p.BirthDate,
                p.TelNo,
                s.Designation,
                s.DesigCode,
                s.Office,
                s.Divisions,
                s.Status
            FROM dbo.xPersonal p
            LEFT JOIN LatestService s ON p.ControlNo = s.ControlNo AND s.rn = 1
            WHERE p.ControlNo = ? OR p.PMID = ?
        ";

        $rows = DB::connection('pmis')->select($sql, [$pmisNo, $pmisNo]);

        return $rows[0] ?? null;
    }

    public function previewRows(int $limit = 10): Collection
    {
        return $this->getActiveEmployees(min(max(1, $limit), 10));
    }

    public function toLocalAttributes(object $employee): array
    {
        return [
            'pmis_no' => $this->normalize(data_get($employee, 'PMISNo')),
            'pmid' => $this->normalize(data_get($employee, 'PMID')),
            'first_name' => $this->normalize(data_get($employee, 'Firstname')),
            'middle_name' => $this->normalize(data_get($employee, 'MIddlename') ?? data_get($employee, 'Middlename')),
            'last_name' => $this->normalize(data_get($employee, 'Surname')),
            'birth_date' => $this->normalizeDate(data_get($employee, 'BirthDate')),
            'designation' => $this->normalize(data_get($employee, 'Designation')),
            'designation_code' => $this->normalize(data_get($employee, 'DesigCode')),
            'office' => $this->normalize(data_get($employee, 'Office')),
            'division' => $this->normalizeDivision(data_get($employee, 'Divisions')),
            'employment_status' => $this->normalize(data_get($employee, 'Status')),
            'telephone' => $this->normalize(data_get($employee, 'TelNo')),
        ];
    }

    public function fullName(object $employee): string
    {
        return collect([
            data_get($employee, 'Firstname'),
            data_get($employee, 'MIddlename') ?? data_get($employee, 'Middlename'),
            data_get($employee, 'Surname'),
        ])
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => trim((string) $value))
            ->implode(' ');
    }

    public function previewQueryDescription(int $limit = 10): string
    {
        return 'SELECT TOP ' . min(max(1, $limit), 10) . ' p.ControlNo AS PMISNo, p.PMID, p.Surname, p.Firstname, p.MIddlename, p.BirthDate, p.TelNo, s.Designation, s.DesigCode, s.Office, s.Divisions, s.Status FROM dbo.xPersonal p LEFT JOIN dbo.xService s ON p.ControlNo = s.ControlNo ORDER BY p.Surname, p.Firstname';
    }

    public function syncQueryDescription(): string
    {
        return 'SELECT p.ControlNo AS PMISNo, p.PMID, p.Surname, p.Firstname, p.MIddlename, p.BirthDate, p.TelNo, s.Designation, s.DesigCode, s.Office, s.Divisions, s.Status FROM dbo.xPersonal p LEFT JOIN dbo.xService s ON p.ControlNo = s.ControlNo ORDER BY p.Surname, p.Firstname';
    }

    private function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalizeDivision(mixed $value): ?string
    {
        $raw = $this->normalize($value);
        if ($raw === null || strtoupper($raw) === 'NONE') {
            return null;
        }

        return $raw;
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
