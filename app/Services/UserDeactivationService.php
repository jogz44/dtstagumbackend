<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * MSSMS ACCOUNT RETENTION POLICY
 *
 * User records must NEVER be physically deleted.
 *
 * Account removal is implemented only through:
 * - is_active = false
 * - SoftDeletes / deleted_at
 *
 * Never call forceDelete() for User records.
 */
class UserDeactivationService
{
    public function deactivate(User $targetUser, User $actor, AuditService $audit, Request $request): void
    {
        DB::transaction(function () use ($targetUser, $actor, $audit, $request) {
            // Revoke access tokens only. Does not delete the MSSMS user record.
            $targetUser->tokens()->delete();

            $audit->accountHistory(
                $targetUser,
                $actor,
                'account.deactivated',
                'User account access was deactivated.',
                [],
                $request,
            );

            $audit->activity(
                $actor,
                'account.deactivated',
                'User Management',
                "Deactivated account access for {$targetUser->first_name} {$targetUser->last_name}.",
                $request,
            );

            $targetUser->forceFill([
                'is_active' => false,
            ])->save();

            // Laravel SoftDeletes: sets deleted_at. MUST NOT be forceDelete().
            $targetUser->delete();
        });
    }
}
