<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\User;

class EffectivePermissionService
{
    public const ADMIN_PERMISSIONS = ['users.manage', 'users.permissions.manage'];

    public const HIDDEN_MANAGEMENT_PERMISSIONS = [
        'can_return_document',
        'can_share_document',
        'can_view_confidential',
    ];

    public function defaults(User $user): array
    {
        return $user->getPermissionsViaRoles()->pluck('name')->unique()->values()->all();
    }

    public function names(User $user): array
    {
        if (! $user->is_active || $user->trashed()) {
            return [];
        }

        $user->loadMissing(['permissionOverrides', 'permissions']);
        $defaults = $this->defaults($user);
        $direct = $user->permissions->pluck('name')->all();
        $overrides = $user->permissionOverrides->keyBy('permission_name');

        return Permission::query()
            ->where('guard_name', 'web')
            ->pluck('name')
            ->filter(function (string $permission) use ($defaults, $direct, $overrides): bool {
                $override = $overrides->get($permission)?->effect;

                return $override === 'grant'
                    || ($override === null && (in_array($permission, $direct, true) || in_array($permission, $defaults, true)));
            })
            ->values()
            ->all();
    }

    public function allows(User $user, string $permission): bool
    {
        if (! $user->is_active || $user->trashed()) {
            return false;
        }

        $user->loadMissing('permissionOverrides');
        $override = $user->permissionOverrides->firstWhere('permission_name', $permission);

        return $override ? $override->effect === 'grant' : ($user->hasDirectPermission($permission) || in_array($permission, $this->defaults($user), true));
    }

    public function matrix(User $user): array
    {
        $defaults = $this->defaults($user);
        $user->loadMissing(['permissionOverrides', 'permissions']);
        $direct = $user->permissions->pluck('name')->all();
        $overrides = $user->permissionOverrides->keyBy('permission_name');

        return Permission::query()
            ->where('guard_name', 'web')
            ->whereNotIn('name', self::HIDDEN_MANAGEMENT_PERMISSIONS)
            ->orderBy('group_name')
            ->orderBy('name')
            ->get()
            ->map(function (Permission $permission) use ($defaults, $overrides, $direct): array {
                $effect = $overrides->get($permission->name)?->effect ?? (in_array($permission->name, $direct, true) ? 'grant' : null);
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
