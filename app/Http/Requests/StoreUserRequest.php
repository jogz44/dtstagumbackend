<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'employee_id' => $this->input('employee_id', $this->input('employeeId')),
            'office_id' => $this->input('office_id', $this->input('officeId')),
            'division_id' => $this->input('division_id', $this->input('divisionId')),
            'is_active' => $this->has('is_active')
                ? filter_var($this->input('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true
                : ($this->has('status') ? strtolower((string) $this->input('status')) === 'active' : true),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pmis_employee_id' => [
                'required',
                'uuid',
                Rule::exists('pmis_employees', 'id'),
                Rule::unique('users', 'pmis_employee_id')->whereNull('deleted_at'),
            ],
            'role' => ['nullable', 'string', Rule::exists('roles', 'name')],
            'username' => ['nullable', 'string', 'max:255', Rule::unique('users', 'username')->whereNull('deleted_at')],
            'office_id' => ['nullable', 'uuid', Rule::exists('offices', 'id')],
            'division_id' => ['nullable', 'uuid', Rule::exists('divisions', 'id')],
            'employee_id' => ['nullable', 'string', 'max:255'],
            'birthdate' => ['nullable', 'date_format:Y-m-d'],
            'position' => ['nullable', 'string', 'max:255'],
            'employment_status' => ['nullable', 'string'],
            'avatar_url' => ['nullable', 'url'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'pmis_employee_id.required' => 'Every portal user account must be linked to a PMIS employee record.',
            'pmis_employee_id.exists' => 'The selected PMIS employee record does not exist.',
            'pmis_employee_id.unique' => 'This PMIS employee already has a linked portal account.',
            'role.required' => 'System role is required.',
            'role.exists' => 'The selected system role is invalid.',
        ];
    }
}
