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
            'is_active' => $this->input('is_active', $this->has('status') ? strtolower((string) $this->input('status')) === 'active' : $this->input('is_active')),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $divisionRule = Rule::exists('divisions', 'id');

        if ($this->filled('office_id')) {
            $divisionRule->where('office_id', (string) $this->input('office_id'));
        }

        return [
            'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')->whereNull('deleted_at')],
            'first_name' => ['required_without:pmis_employee_id', 'nullable', 'string', 'max:255'],
            'last_name' => ['required_without:pmis_employee_id', 'nullable', 'string', 'max:255'],
            'office_id' => ['required', 'uuid', Rule::exists('offices', 'id')],
            'division_id' => ['nullable', 'uuid', $divisionRule],
            'employee_id' => ['nullable', 'string', 'max:255'],
            'pmis_employee_id' => ['nullable', 'uuid', Rule::exists('pmis_employees', 'id'), Rule::unique('users', 'pmis_employee_id')->whereNull('deleted_at')],
            'birthdate' => ['required_without:pmis_employee_id', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'position' => ['nullable', 'string', 'max:255'],
            'employment_status' => ['nullable', 'string', Rule::in(['Permanent', 'Regular', 'Casual', 'Contractual', 'Job Order', 'Co-Terminus', 'Honorarium', 'regular', 'casual', 'contractual', 'honorarium', 'co-terminous', 'co-terminus', 'job order'])],
            'avatar_url' => ['nullable', 'url'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['boolean'],
            'role' => ['nullable', 'string', Rule::exists('roles', 'name')],
        ];
    }
}
