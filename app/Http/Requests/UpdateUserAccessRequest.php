<?php

namespace App\Http\Requests;

use App\Models\Role;
use App\Models\User;
use App\Support\RoleNames;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        return $user->is_active && $user->hasAnyRole(RoleNames::adminRoles());
    }

    public function rules(): array
    {
        $routeUser = $this->route('user');
        $userId = $routeUser instanceof User ? $routeUser->id : $routeUser;

        return [
            'username' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique((new User)->getTable(), 'username')->ignore($userId),
            ],
            'role_id' => [
                'sometimes',
                'required',
                'string',
                Rule::exists((new Role)->getTable(), 'id')->where(
                    fn (Builder $query) => $query
                        ->where('guard_name', 'web')
                        ->whereIn('name', RoleNames::defaults())
                        ->whereNull('deleted_at'),
                ),
            ],
            'is_active' => ['sometimes', 'required', 'boolean'],

            'permission_overrides' => ['sometimes', 'array'],
            'permission_overrides.*.permission_name' => ['required_with:permission_overrides', 'string', 'distinct', Rule::exists('permissions', 'name')->where('guard_name', 'web')->whereNull('deleted_at')],
            'permission_overrides.*.effect' => ['required_with:permission_overrides', 'string', Rule::in(['grant', 'deny'])],

            'first_name' => ['prohibited'],
            'last_name' => ['prohibited'],
            'middle_name' => ['prohibited'],
            'employee_id' => ['prohibited'],
            'employeeId' => ['prohibited'],
            'pmis_employee_id' => ['prohibited'],
            'office_id' => ['prohibited'],
            'officeId' => ['prohibited'],
            'office' => ['prohibited'],
            'division_id' => ['prohibited'],
            'divisionId' => ['prohibited'],
            'division' => ['prohibited'],
            'contact_number' => ['prohibited'],
            'contactNumber' => ['prohibited'],
            'position' => ['prohibited'],
            'employment_status' => ['prohibited'],
            'email' => ['prohibited'],
            'birthdate' => ['prohibited'],
            'birth_date' => ['prohibited'],
            'password' => ['prohibited'],
        ];
    }
}
