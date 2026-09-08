<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\RoleNames;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserAccessService
{
    public function __construct(
        private readonly AdministratorProtectionService $administratorProtection,
        private readonly AuditService $audit,
    ) {}

    public function update(User $actor, User $targetUser, array $attributes, Request $request): User
    {
        abort_unless($actor->is_active && $actor->hasAnyRole(RoleNames::adminRoles()), 403);
        abort_if(
            $actor->id !== $targetUser->id && $targetUser->hasAnyRole(RoleNames::adminRoles()),
            403,
            'Administrator accounts cannot be edited by another administrator.'
        );

        return DB::transaction(function () use ($actor, $targetUser, $attributes, $request) {
            $newRole = array_key_exists('role_id', $attributes)
                ? Role::query()
                    ->whereKey($attributes['role_id'])
                    ->where('guard_name', 'web')
                    ->whereIn('name', RoleNames::defaults())
                    ->firstOrFail()
                : null;

            $newIsActive = array_key_exists('is_active', $attributes)
                ? (bool) $attributes['is_active']
                : null;

            $user = $this->administratorProtection->lockAndEnsureTransition(
                $actor,
                $targetUser,
                $newRole,
                $newIsActive,
            );

            $previousRole = $user->roles->first();
            $previousUsername = $user->username;
            $previousIsActive = (bool) $user->is_active;
            $wasTrashed = $user->trashed();

            if (array_key_exists('username', $attributes) && $attributes['username'] !== $user->username) {
                $user->forceFill(['username' => $attributes['username']])->save();
                $this->recordUsernameChange($user, $actor, $previousUsername, $request);
            }

            if ($newRole !== null && $previousRole?->id !== $newRole->id) {
                if (in_array($newRole->name, RoleNames::adminRoles(), true) && $user->permissionOverrides()
                    ->whereIn('permission_name', EffectivePermissionService::ADMIN_PERMISSIONS)->where('effect', 'deny')->exists()) {
                    throw ValidationException::withMessages([
                        'role_id' => 'Remove management permission denials before assigning Administrator. Other overrides will be preserved.',
                    ]);
                }
                $user->syncRoles([$newRole->name]);
                $this->recordRoleChange($user, $actor, $previousRole, $newRole, $request);
            }

            if ($newIsActive === true && ($wasTrashed || ! $previousIsActive)) {
                if ($wasTrashed) {
                    $user->restore();
                }

                $user->forceFill(['is_active' => true])->save();
                $this->recordStatusChange($user, $actor, $previousIsActive, true, $wasTrashed, $request);
            }

            if ($newIsActive === false && ($previousIsActive || ! $wasTrashed)) {
                $user->tokens()->delete();
                $user->forceFill(['is_active' => false])->save();
                $user->delete();
                $this->recordStatusChange($user, $actor, $previousIsActive, false, false, $request);
            }

            if (array_key_exists('permission_overrides', $attributes)) {
                $overrides = $attributes['permission_overrides'];
                if ($user->hasAnyRole(RoleNames::adminRoles())) {
                    foreach ($overrides as $override) {
                        if (in_array($override['permission_name'], EffectivePermissionService::ADMIN_PERMISSIONS, true) && $override['effect'] === 'deny') {
                            throw ValidationException::withMessages([
                                'permission_overrides' => 'Administrator management permissions cannot be denied. Demote the account first if appropriate.',
                            ]);
                        }
                    }
                }
                $this->syncPermissionOverrides($user, $actor, $overrides, $request);
            }

            return $user->fresh(['office', 'division', 'roles', 'pmisEmployee', 'permissionOverrides']);
        }, attempts: 3);
    }

    private function recordUsernameChange(User $user, User $actor, string $previousUsername, Request $request): void
    {
        $this->audit->accountHistory(
            $user,
            $actor,
            'account.username_changed',
            "Username was updated from {$previousUsername} to {$user->username}.",
            ['username' => ['before' => $previousUsername, 'after' => $user->username]],
            $request,
        );

        $this->audit->activity(
            $actor,
            'account.username_changed',
            'User Management',
            "Updated username for {$user->full_name} to {$user->username}.",
            $request,
            ['resource_type' => 'User', 'resource_id' => $user->id],
        );
    }

    private function recordRoleChange(
        User $user,
        User $actor,
        ?Role $previousRole,
        Role $newRole,
        Request $request,
    ): void {
        $label = RoleNames::label($newRole->name);

        $this->audit->accountHistory(
            $user,
            $actor,
            'account.role_changed',
            "User role was changed to {$label}.",
            ['role' => ['before' => $previousRole?->name, 'after' => $newRole->name]],
            $request,
        );

        $this->audit->activity(
            $actor,
            'account.role_changed',
            'User Management',
            "Changed role for {$user->full_name} to {$label}.",
            $request,
            ['resource_type' => 'User', 'resource_id' => $user->id],
        );
    }

    private function recordStatusChange(
        User $user,
        User $actor,
        bool $previousIsActive,
        bool $isActive,
        bool $wasRestored,
        Request $request,
    ): void {
        $eventType = $isActive
            ? ($wasRestored ? 'account.reactivated' : 'account.activated')
            : 'account.deactivated';
        $summary = $isActive
            ? ($wasRestored ? 'User account access was restored and activated.' : 'User account access was activated.')
            : 'User account access was deactivated.';

        $this->audit->accountHistory(
            $user,
            $actor,
            $eventType,
            $summary,
            ['is_active' => ['before' => $previousIsActive, 'after' => $isActive]],
            $request,
        );

        $this->audit->activity(
            $actor,
            $eventType,
            'User Management',
            "{$summary} User: {$user->full_name}.",
            $request,
            ['resource_type' => 'User', 'resource_id' => $user->id],
        );
    }

    private function syncPermissionOverrides(User $user, User $actor, array $overrides, Request $request): void
    {
        $previousOverrides = $user->permissionOverrides()->get()
            ->map(fn ($ov) => ['permission_name' => $ov->permission_name, 'effect' => $ov->effect])
            ->values()
            ->toArray();

        $user->syncPermissions([]);
        $user->permissionOverrides()->delete();

        foreach ($overrides as $override) {
            UserPermissionOverride::create([
                'user_id' => $user->id,
                'permission_name' => $override['permission_name'],
                'effect' => $override['effect'],
            ]);
        }

        $newOverrides = collect($overrides)
            ->map(fn ($ov) => ['permission_name' => $ov['permission_name'], 'effect' => $ov['effect']])
            ->values()
            ->toArray();

        if ($previousOverrides !== $newOverrides) {
            $this->audit->accountHistory(
                $user,
                $actor,
                'user.permission_overrides_updated',
                'Permission overrides were updated for '.$user->full_name.'.',
                [
                    'permission_overrides' => [
                        'before' => $previousOverrides,
                        'after' => $newOverrides,
                    ],
                ],
                $request,
            );

            $this->audit->activity(
                $actor,
                'user.permission_overrides_updated',
                'User Management',
                'Updated permission overrides for '.$user->full_name.'.',
                $request,
                ['resource_type' => 'User', 'resource_id' => $user->id],
            );
        }
    }
}
