<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $role = $this->roles->first() ?: $this->roles()->first();

        return [
            'id' => $this->id,
            'username' => $this->username,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'employee_id' => $this->employee_id,
            'birthdate' => $this->birthdate?->toDateString(),
            'position' => $this->position,
            'employment_status' => $this->employment_status,
            'avatar_url' => $this->avatar_url,
            'is_active' => $this->is_active,
            'must_change_password' => $this->must_change_password,
            'office' => $this->whenLoaded('office'),
            'division' => $this->whenLoaded('division'),
            'role' => $role ? [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
                'description' => $role->description,
                'users_count' => $this->usersCountForRole($role->id),
            ] : null,
            'roles' => $this->getRoleNames()->values(),
            'permissions' => $this->getAllPermissions()
                ->sortBy([['group_name', 'asc'], ['name', 'asc']])
                ->values()
                ->map(fn ($permission) => [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'label' => $this->permissionLabel($permission->name),
                    'group_name' => $permission->group_name,
                    'description' => $permission->description,
                ])
                ->values(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
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

    private function usersCountForRole(string $roleId): int
    {
        return DB::table(config('permission.table_names.model_has_roles'))
            ->where('role_id', $roleId)
            ->where('model_type', config('auth.providers.users.model'))
            ->count();
    }
}
