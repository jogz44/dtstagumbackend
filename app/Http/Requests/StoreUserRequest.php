<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'password' => ['required', 'string', 'min:7'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'office_id' => ['nullable', 'uuid', Rule::exists('offices', 'id')],
            'division_id' => ['nullable', 'uuid', Rule::exists('divisions', 'id')],
            'employee_id' => ['nullable', 'string', 'max:255'],
            'birthdate' => ['nullable', 'date'],
            'position' => ['nullable', 'string', 'max:255'],
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
