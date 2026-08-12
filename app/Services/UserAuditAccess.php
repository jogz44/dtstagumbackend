<?php

namespace App\Services;

use App\Models\User;
use App\Support\RoleNames;

class UserAuditAccess
{
    public function canRead(User $authUser, User $targetUser): bool
    {
        if ($authUser->hasRole(RoleNames::ADMIN)) {
            return true;
        }

        return $authUser->hasRole(RoleNames::SUPERVISOR)
            && $authUser->office_id === $targetUser->office_id
            && $authUser->division_id === $targetUser->division_id;
    }

    public function authorize(User $authUser, User $targetUser): void
    {
        if ($this->canRead($authUser, $targetUser)) {
            return;
        }

        abort(403, 'You are not authorized to view this user audit data.');
    }
}
