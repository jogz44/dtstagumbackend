<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Support\RoleNames;
use Illuminate\Http\Exceptions\HttpResponseException;

class AdministratorProtectionService
{
    public function lockAndEnsureTransition(
        User $actor,
        User $targetUser,
        ?Role $newRole,
        ?bool $newIsActive,
    ): User {
        $activeAdministrators = User::query()
            ->with('roles')
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', RoleNames::adminRoles()))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $lockedTarget = User::withTrashed()
            ->with('roles')
            ->whereKey($targetUser->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($actor->id === $lockedTarget->id && $newIsActive === false) {
            $this->reject('is_active', 'You cannot deactivate your own account.');
        }

        $isAdministrator = $lockedTarget->hasAnyRole(RoleNames::adminRoles());

        if (! $isAdministrator) {
            return $lockedTarget;
        }

        $isDemotion = $newRole !== null && ! in_array($newRole->name, RoleNames::adminRoles(), true);
        $isDeactivation = $newIsActive === false;

        if (! $isDemotion && ! $isDeactivation) {
            return $lockedTarget;
        }

        if ($activeAdministrators->count() <= 1) {
            $field = $isDemotion ? 'role_id' : 'is_active';
            $this->reject($field, 'Cannot modify or deactivate the last administrator account.');
        }

        return $lockedTarget;
    }

    private function reject(string $field, string $message): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'errors' => [
                $field => [$message],
            ],
        ], 422));
    }
}
