<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $input = [];

        if ($this->has('employeeId') && ! $this->has('employee_id')) {
            $input['employee_id'] = $this->input('employeeId');
        }

        if ($this->has('officeId') && ! $this->has('office_id')) {
            $input['office_id'] = $this->input('officeId');
        }

        if ($this->has('divisionId') && ! $this->has('division_id')) {
            $input['division_id'] = $this->input('divisionId');
        }

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
        $officeId = $this->input('office_id') ?? (is_object($routeUser) ? $routeUser->office_id : null);
        $divisionRule = Rule::exists('divisions', 'id');

        if ($officeId) {
            $divisionRule->where('office_id', (string) $officeId);
        }

        return [
            'username' => ['sometimes', 'string', 'max:255', Rule::unique('users', 'username')->ignore($userId)->whereNull('deleted_at')],
            'password' => ['sometimes', 'nullable', 'string', 'min:7'],
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'office_id' => ['nullable', 'uuid', Rule::exists('offices', 'id')],
            'division_id' => ['nullable', 'uuid', $divisionRule],
            'employee_id' => ['nullable', 'string', 'max:255'],
            'birthdate' => ['nullable', 'date'],
            'position' => ['nullable', 'string', 'max:255'],
            'employment_status' => ['nullable', 'string', Rule::in(['Permanent', 'Regular', 'Casual', 'Contractual', 'Job Order', 'Co-Terminus', 'Honorarium', 'regular', 'casual', 'contractual', 'honorarium', 'co-terminous', 'co-terminus', 'job order'])],
            'avatar_url' => ['nullable', 'url'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'role' => ['nullable', 'string', Rule::exists('roles', 'name')],
        ];
    }
}
