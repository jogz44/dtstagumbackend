<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\User;

class EffectivePermissionService
{
    public const ADMIN_PERMISSIONS = ['users.manage', 'users.permissions.manage'];

    public function defaults(User $user): array
    {
        return $user->getPermissionsViaRoles()->pluck('name')->unique()->values()->all();
    }

    public function names(User $user): array
    {
        return collect($this->matrix($user))->where('allowed', true)->pluck('name')->all();
    }

    public function allows(User $user, string $permission): bool
    {
        if (! $user->is_active || $user->trashed()) {
            return false;
        }

        $user->loadMissing('permissionOverrides');
        $override = $user->permissionOverrides->firstWhere('permission_name', $permission);

        return $override ? $override->effect === 'grant' : in_array($permission, $this->defaults($user), true);
    }

    public function matrix(User $user): array
    {
        $defaults = $this->defaults($user);
        $user->loadMissing('permissionOverrides');
        $overrides = $user->permissionOverrides->keyBy('permission_name');

        return Permission::query()->where('guard_name', 'web')->orderBy('group_name')->orderBy('name')->get()
            ->map(function (Permission $permission) use ($defaults, $overrides): array {
                $effect = $overrides->get($permission->name)?->effect;
                $inherited = in_array($permission->name, $defaults, true);

                return [
                    'name' => $permission->name,
                    'label' => str($permission->name)->replace(['can_', '.', '_'], ['', ' ', ' '])->title()->toString(),
                    'group_name' => $permission->group_name,
                    'description' => $permission->description,
                    'role_default' => $inherited,
                    'override' => $effect ?? 'inherit',
                    'allowed' => $effect === null ? $inherited : $effect === 'grant',
                ];
            })->all();
    }
}
