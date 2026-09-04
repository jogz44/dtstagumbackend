<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\RoleNames;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserAccessService
{
    public function __construct(
        private readonly AdministratorProtectionService $administratorProtection,
        private readonly AuditService $audit,
    ) {}

    public function update(User $actor, User $targetUser, array $attributes, Request $request): User
    {
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
                $overridesToPurge = $user->permissionOverrides()->get();
                if ($overridesToPurge->isNotEmpty()) {
                    $purgedList = $overridesToPurge->map(fn ($ov) => [
                        'permission_name' => $ov->permission_name,
                        'effect' => $ov->effect,
                    ])->values()->toArray();

                    $user->permissionOverrides()->delete();

                    $this->audit->accountHistory(
                        $user,
                        $actor,
                        'user.permission_overrides_purged',
                        "Permission overrides were purged due to role change from {$previousRole?->name} to {$newRole->name}.",
                        [
                            'role' => [
                                'before' => $previousRole?->name,
                                'after' => $newRole->name,
                            ],
                            'permission_overrides' => [
                                'before' => $purgedList,
                                'after' => [],
                            ],
                        ],
                        $request,
                    );

                    $this->audit->activity(
                        $actor,
                        'user.permission_overrides_purged',
                        'User Management',
                        'Purged '.count($purgedList)." permission override(s) for {$user->full_name} during role change to {$newRole->name}.",
                        $request,
                        ['resource_type' => 'User', 'resource_id' => $user->id, 'purged_count' => count($purgedList)],
                    );
                } else {
                    $user->permissionOverrides()->delete();
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
                $currentRoleName = strtolower($user->roles->first()?->name ?? '');
                if (! in_array($currentRoleName, RoleNames::adminRoles(), true)) {
                    $this->syncPermissionOverrides($user, $actor, $attributes['permission_overrides'], $request);
                }
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
