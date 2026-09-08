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
            'barangay_id' => $this->input('barangay_id', $this->input('barangayId')),
            'first_name' => $this->input('first_name', $this->input('firstName')),
            'last_name' => $this->input('last_name', $this->input('lastName')),
            'middle_name' => $this->input('middle_name', $this->input('middleName')),
            'is_active' => $this->has('is_active')
                ? filter_var($this->input('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true
                : ($this->has('status') ? strtolower((string) $this->input('status')) === 'active' : true),
        ]);
    }

    public function authorize(): bool
    {
        $authUser = $this->user();
        if (! $authUser) {
            return false;
        }

        return $authUser->is_active && $authUser->hasAnyRole(\App\Support\RoleNames::adminRoles());
    }

    public function rules(): array
    {
        return [
            'pmis_employee_id' => [
                'nullable',
                'uuid',
                Rule::exists('pmis_employees', 'id'),
                Rule::unique('users', 'pmis_employee_id'),
            ],
            'first_name' => ['required_without:pmis_employee_id', 'nullable', 'string', 'max:100'],
            'last_name' => ['required_without:pmis_employee_id', 'nullable', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'birthdate' => ['required_without:pmis_employee_id', 'nullable', 'date_format:Y-m-d', 'before:today'],
            'barangay_id' => [
                'required_without:pmis_employee_id',
                'nullable',
                'integer',
                Rule::exists('barangays', 'id')->where('is_active', true),
            ],
            'role' => ['required', 'string', Rule::exists('roles', 'name')],
            'username' => ['nullable', 'string', 'max:255', Rule::unique('users', 'username')],
            'office_id' => ['nullable', 'uuid', Rule::exists('offices', 'id')],
            'division_id' => ['nullable', 'uuid', Rule::exists('divisions', 'id')],
            'employee_id' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'employment_status' => ['nullable', 'string'],
            'avatar_url' => ['nullable', 'url'],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->whereNotNull('email'),
            ],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required_without' => 'First name is required when not choosing a PMIS employee.',
            'last_name.required_without' => 'Last name is required when not choosing a PMIS employee.',
            'birthdate.required_without' => 'Birthdate is required when not choosing a PMIS employee.',
            'barangay_id.required_without' => 'Barangay selection is required for manual user registration.',
            'barangay_id.exists' => 'The selected Barangay is invalid.',
            'pmis_employee_id.exists' => 'The selected PMIS employee record does not exist.',
            'pmis_employee_id.unique' => 'This PMIS employee already has a linked portal account.',
            'role.required' => 'System role is required.',
            'role.exists' => 'The selected system role is invalid.',
            'email.unique' => 'This email address is already in use by another account.',
        ];
    }
}
