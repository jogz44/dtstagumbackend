<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $input = [];

        $rawActive = $this->has('is_active')
            ? $this->input('is_active')
            : ($this->has('status') ? $this->input('status') : null);

        if ($rawActive !== null) {
            $normalized = $this->normalizeBoolean($rawActive);
            if ($normalized !== null) {
                $input['is_active'] = $normalized;
            } else {
                $input['is_active'] = $rawActive;
            }
        }

        if ($input !== []) {
            $this->merge($input);
        }
    }

    private function normalizeBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 1 || $value === '1' || $value === 'true' || $value === 'Active' || $value === 'active') {
            return true;
        }

        if ($value === 0 || $value === '0' || $value === 'false' || $value === 'Inactive' || $value === 'inactive') {
            return false;
        }

        return null;
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $routeUser = $this->route('user');
        $userId = is_object($routeUser) ? $routeUser->id : $routeUser;

        return [
            'username' => ['sometimes', 'string', 'max:255', Rule::unique('users', 'username')->ignore($userId)->whereNull('deleted_at')],
            'is_active' => ['sometimes', 'boolean'],

            'first_name' => ['prohibited'],
            'last_name' => ['prohibited'],
            'fullName' => ['prohibited'],
            'full_name' => ['prohibited'],
            'employee_id' => ['prohibited'],
            'employeeId' => ['prohibited'],
            'birthdate' => ['prohibited'],
            'birth_date' => ['prohibited'],
            'office_id' => ['prohibited'],
            'officeId' => ['prohibited'],
            'division_id' => ['prohibited'],
            'divisionId' => ['prohibited'],
            'position' => ['prohibited'],
            'employment_status' => ['prohibited'],
            'employmentStatus' => ['prohibited'],
            'role' => ['prohibited'],
            'role_id' => ['prohibited'],
            'roles' => ['prohibited'],
            'password' => ['prohibited'],
            'pmis_employee_id' => ['prohibited'],
            'pmisEmployeeId' => ['prohibited'],
            'email' => ['prohibited'],
            'avatar_url' => ['prohibited'],
            'avatarUrl' => ['prohibited'],
        ];
    }
}
