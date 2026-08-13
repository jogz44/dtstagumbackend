<?php

namespace App\Services;

use App\Models\PmisEmployee;
use App\Models\User;
use InvalidArgumentException;

class UserInitialPasswordService
{
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

        return $employee->birth_date->format('mdY');
    }

    /**
     * Check if a proposed password matches the zero-padded MMDDYYYY format of the employee's birth date.
     */
    public function matchesEmployeeBirthday(?PmisEmployee $employee, string $proposedPassword): bool
    {
        if ($employee === null || $employee->birth_date === null) {
            return false;
        }

        $birthdayPassword = $employee->birth_date->format('mdY');

        return trim($proposedPassword) === $birthdayPassword;
    }
}
