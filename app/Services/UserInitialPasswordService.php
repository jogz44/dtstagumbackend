<?php

namespace App\Services;

use App\Models\PmisEmployee;
use App\Models\User;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class UserInitialPasswordService
{
    /**
     * Generate zero-padded MMDDYYYY temporary password from birthdate (string 'Y-m-d' or Carbon).
     */
    public function fromBirthdate(string|Carbon $birthdate): string
    {
        if (empty($birthdate)) {
            throw new InvalidArgumentException('Birthdate is required to generate initial temporary password.');
        }

        if ($birthdate instanceof Carbon) {
            return $birthdate->format('mdY');
        }

        $dateStr = trim((string) $birthdate);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            return Carbon::createFromFormat('Y-m-d', $dateStr)->format('mdY');
        }

        return Carbon::parse($dateStr)->format('mdY');
    }

    /**
     * Generate zero-padded MMDDYYYY temporary password from synchronized PMIS birth_date.
     */
    public function fromEmployee(PmisEmployee $employee): string
    {
        if ($employee->birth_date === null) {
            throw new InvalidArgumentException(
                'This employee does not have a verified birth date in PMIS and cannot be issued a birthday-based temporary password.'
            );
        }

        return $this->fromBirthdate($employee->birth_date);
    }

    /**
     * Check if a proposed password matches the zero-padded MMDDYYYY format of the employee's birth date.
     */
    public function matchesEmployeeBirthday(?PmisEmployee $employee, string $proposedPassword): bool
    {
        if ($employee === null || $employee->birth_date === null) {
            return false;
        }

        $birthdayPassword = $this->fromBirthdate($employee->birth_date);

        return trim($proposedPassword) === $birthdayPassword;
    }

    /**
     * Check if a proposed password matches zero-padded MMDDYYYY format of a user's birthdate.
     */
    public function matchesUserBirthdate(?User $user, string $proposedPassword): bool
    {
        if ($user === null || $user->birthdate === null) {
            return false;
        }

        $birthdayPassword = $this->fromBirthdate($user->birthdate);

        return trim($proposedPassword) === $birthdayPassword;
    }
}
