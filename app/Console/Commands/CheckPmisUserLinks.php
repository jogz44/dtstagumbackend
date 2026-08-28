<?php

namespace App\Console\Commands;

use App\Models\PmisEmployee;
use App\Models\User;
use Illuminate\Console\Command;

class CheckPmisUserLinks extends Command
{
    protected $signature = 'pmis:check-user-links {--samples=5 : Number of matched/unmatched sample users to display}';

    protected $description = 'Assess read-only links between users.employee_id and pmis_employees.pmis_no';

    public function handle(): int
    {
        $sampleLimit = max(1, (int) $this->option('samples'));

        $this->info('====================================================');
        $this->info(' PMIS User Link Assessment');
        $this->info('====================================================');
        $this->line('Local user key candidate: users.employee_id');
        $this->line('Local PMIS key candidate: pmis_employees.pmis_no');
        $this->line('Mode: read-only assessment, no user or PMIS changes');
        $this->line('----------------------------------------------------');

        $users = User::query()
            ->select(['id', 'username', 'first_name', 'last_name', 'employee_id'])
            ->orderBy('username')
            ->get();

        $employeeIds = $users
            ->pluck('employee_id')
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => $this->normalize($value))
            ->unique()
            ->values();

        $pmisEmployeesByNo = PmisEmployee::query()
            ->select(['pmis_no', 'first_name', 'middle_name', 'last_name', 'designation', 'office', 'employment_status'])
            ->whereIn('pmis_no', $employeeIds)
            ->get()
            ->keyBy('pmis_no');

        $usersWithEmployeeId = 0;
        $blankEmployeeId = 0;
        $matched = 0;
        $unmatched = 0;
        $matchedSamples = [];
        $unmatchedSamples = [];

        foreach ($users as $user) {
            $employeeId = $this->normalize($user->employee_id);

            if ($employeeId === null) {
                $blankEmployeeId++;
                continue;
            }

            $usersWithEmployeeId++;

            $pmisEmployee = $pmisEmployeesByNo->get($employeeId);

            if ($pmisEmployee) {
                $matched++;
                $this->pushSample($matchedSamples, [
                    $user->username,
                    $this->userFullName($user),
                    $employeeId,
                    $this->pmisFullName($pmisEmployee),
                    $pmisEmployee->office,
                    $pmisEmployee->employment_status,
                ], $sampleLimit);
                continue;
            }

            $unmatched++;
            $this->pushSample($unmatchedSamples, [
                $user->username,
                $this->userFullName($user),
                $employeeId,
            ], $sampleLimit);
        }

        $duplicateEmployeeIds = $users
            ->pluck('employee_id')
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => $this->normalize($value))
            ->countBy()
            ->filter(fn ($count) => $count > 1);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total users', $users->count()],
                ['Users with employee_id', $usersWithEmployeeId],
                ['Users whose employee_id matches pmis_employees.pmis_no', $matched],
                ['Users with employee_id but no PMIS match', $unmatched],
                ['Users with blank/null employee_id', $blankEmployeeId],
                ['Duplicate employee_id values in users', $duplicateEmployeeIds->count()],
            ]
        );

        if ($duplicateEmployeeIds->isNotEmpty()) {
            $this->newLine();
            $this->warn('Duplicate employee_id values');
            $this->table(
                ['employee_id', 'user_count'],
                $duplicateEmployeeIds
                    ->map(fn ($count, $employeeId) => [$employeeId, $count])
                    ->values()
                    ->all()
            );
        }

        $this->newLine();
        $this->info('Sample matched users');
        $this->table(
            ['Username', 'User Name', 'employee_id', 'PMIS Name', 'PMIS Office', 'PMIS Status'],
            $matchedSamples
        );

        $this->newLine();
        $this->info('Sample unmatched users');
        $this->table(
            ['Username', 'User Name', 'employee_id'],
            $unmatchedSamples
        );

        $this->newLine();
        $this->comment('Assessment complete. No PMIS data, user rows, or local employee rows were modified.');

        return self::SUCCESS;
    }

    private function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function pushSample(array &$samples, array $sample, int $limit): void
    {
        if (count($samples) < $limit) {
            $samples[] = $sample;
        }
    }

    private function userFullName(User $user): string
    {
        return trim("{$user->first_name} {$user->last_name}");
    }

    private function pmisFullName(PmisEmployee $employee): string
    {
        return collect([
            $employee->first_name,
            $employee->middle_name,
            $employee->last_name,
        ])
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => trim((string) $value))
            ->implode(' ');
    }
}
