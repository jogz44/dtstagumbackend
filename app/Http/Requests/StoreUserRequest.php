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
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'office_id' => ['required', 'uuid', Rule::exists('offices', 'id')],
            'division_id' => ['required', 'uuid', $divisionRule],
            'employee_id' => ['nullable', 'string', 'max:255'],
            'birthdate' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'position' => ['nullable', 'string', 'max:255'],
            'employment_status' => ['nullable', 'string', Rule::in(['regular', 'casual', 'contractual', 'honorarium', 'co-terminous'])],
            'avatar_url' => ['nullable', 'url'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['boolean'],
            'role' => ['nullable', 'string', Rule::exists('roles', 'name')],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
            'permission_overrides' => ['nullable', 'array'],
            'permission_overrides.*.name' => ['required_with:permission_overrides', 'string', Rule::exists('permissions', 'name')],
            'permission_overrides.*.effect' => ['required_with:permission_overrides', Rule::in(['allow', 'deny'])],
        ];
    }
}
