<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Create a new resource instance.
     *
     * @param  mixed  $resource
     */
    public function __construct($resource)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $role = $this->relationLoaded('roles') ? $this->roles->first() : null;
        $roleName = $role?->name ? $this->normalizeRoleName($role->name) : null;

        return [
            'id' => $this->id,
            'username' => $this->username,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'employee_id' => $this->employee_id,
            'pmis_employee_id' => $this->pmis_employee_id,
            'birthdate' => $this->birthdate?->toDateString(),
            'position' => $this->position,
            'employment_status' => $this->employment_status,
            'avatar_url' => $this->avatar_url,
            'is_active' => $this->is_active,
            'must_change_password' => $this->must_change_password,
            'office' => $this->whenLoaded('office'),
            'division' => $this->whenLoaded('division'),
            'pmis_employee' => $this->whenLoaded('pmisEmployee'),
            'role' => $role ? [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
                'description' => $role->description,
            ] : null,
            'role_name' => $roleName,
            'role_label' => $roleName === 'admin' ? 'Admin' : ($role?->display_name ?: null),
            'roles' => $this->relationLoaded('roles') ? $this->roles->pluck('name')->values() : [],
            'permissions' => $this->relationLoaded('permissions')
                ? $this->permissions
                    ->sortBy([['group_name', 'asc'], ['name', 'asc']])
                    ->values()
                    ->map(fn ($permission) => [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'label' => $this->permissionLabel($permission->name),
                        'group_name' => $permission->group_name,
                        'description' => $permission->description,
                    ])
                    ->values()
                : [],
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function permissionLabel(string $name): string
    {
        return str($name)
            ->replace(['.', '_', '-'], ' ')
            ->title()
            ->toString();
    }

    private function normalizeRoleName(?string $roleName): ?string
    {
        if (! $roleName) {
            return null;
        }

        $value = str($roleName)->lower()->trim()->replaceMatches('/[-\s]+/', '_')->toString();

        return in_array($value, ['admin', 'administrator', 'system_administrator', 'super_admin'], true)
            ? 'admin'
            : $value;
    }
}
