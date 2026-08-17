<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditService;
use App\Support\RoleNames;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSecurityController extends Controller
{
    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorizeSecurityRead($request->user(), $user);
        $currentTokenId = $request->user()->currentAccessToken()?->id;

        return response()->json([
            'username' => $user->username,
            'must_change_password' => $user->must_change_password,
            'password_changed_at' => $user->must_change_password ? null : $user->updated_at?->toIso8601String(),
            'failed_login_attempts' => 0,
            'locked' => ! $user->is_active,
            'locked_at' => null,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'settings' => [
                'require_strong_password' => true,
                'password_expiry_days' => 90,
                'session_timeout_minutes' => (int) config('session.lifetime', 120),
                'login_notifications' => true,
                'ip_restriction' => false,
            ],
            'sessions' => $user->tokens()
                ->latest()
                ->get()
                ->map(fn ($token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'device' => 'Sanctum token',
                    'ip_address' => null,
                    'location' => 'Tagum City, PH',
                    'last_activity_at' => $token->last_used_at?->toIso8601String(),
                    'issued_at' => $token->created_at?->toIso8601String(),
                    'expires_at' => $token->expires_at?->toIso8601String(),
                    'current' => $token->id === $currentTokenId,
                ])
                ->values(),
        ]);
    }

    public function resetPassword(Request $request, User $user, AuditService $audit): JsonResponse
    {
        $this->authorizeSecurityAction($request->user());

        if ($user->trashed()) {
            return response()->json([
                'message' => 'Cannot reset the password of a deleted account.',
            ], 422);
        }

        $birthDate = $user->pmisEmployee?->birth_date ?? ($user->birthdate ? Carbon::parse($user->birthdate) : null);

        if (! $birthDate) {
            return response()->json([
                'message' => 'Cannot reset password because this user has no birthdate on file.',
            ], 422);
        }

        $temporaryPassword = $birthDate->format('mdY');

        DB::transaction(function () use ($request, $user, $audit, $temporaryPassword) {
            $user->tokens()->delete();

            $user->forceFill([
                'password' => Hash::make($temporaryPassword),
                'must_change_password' => true,
            ])->save();

            $audit->accountHistory(
                $user,
                $request->user(),
                'password.reset',
                'Password was reset to the birthdate-based temporary credential.',
                [],
                $request,
            );

            $audit->activity(
                $request->user(),
                'password.reset',
                'Security',
                "Reset password for {$user->first_name} {$user->last_name}.",
                $request,
            );
        });

        return response()->json([
            'message' => 'Password reset successfully.',
            'temporary_password' => $temporaryPassword,
            'must_change_password' => true,
        ]);
    }

    public function revokeSession(Request $request, User $user, int $tokenId, AuditService $audit): JsonResponse
    {
        $this->authorizeSecurityAction($request->user(), $user);

        if ($user->trashed()) {
            return response()->json([
                'message' => 'Cannot revoke sessions for a deleted account.',
            ], 422);
        }

        /** @var \Laravel\Sanctum\PersonalAccessToken|null $token */
        $token = $user->tokens()->whereKey($tokenId)->first();

        if (! $token) {
            return response()->json([
                'message' => 'Session token not found for this user.',
            ], 404);
        }

        $token->delete();

        $audit->accountHistory(
            $user,
            $request->user(),
            'session.revoked',
            'One active access token was revoked.',
            ['token_id' => $tokenId],
            $request,
        );

        $audit->activity(
            $request->user(),
            'session.revoked',
            'Security',
            "Revoked an active session for {$user->first_name} {$user->last_name}.",
            $request,
        );

        return response()->json(['message' => 'Session revoked.']);
    }

    public function revokeOtherSessions(Request $request, User $user, AuditService $audit): JsonResponse
    {
        $this->authorizeSecurityAction($request->user(), $user);

        if ($user->trashed()) {
            return response()->json([
                'message' => 'Cannot revoke sessions for a deleted account.',
            ], 422);
        }

        $currentTokenId = $request->user()->currentAccessToken()?->id;
        $query = $user->tokens();

        if ($request->user()->id === $user->id && $currentTokenId) {
            $query->whereKeyNot($currentTokenId);
        }

        $deleted = $query->delete();

        $audit->accountHistory(
            $user,
            $request->user(),
            'sessions.revoked',
            'Active access tokens were revoked.',
            ['revoked_count' => $deleted],
            $request,
        );

        $audit->activity(
            $request->user(),
            'sessions.revoked',
            'Security',
            "Revoked {$deleted} active session(s) for {$user->first_name} {$user->last_name}.",
            $request,
        );

        return response()->json([
            'message' => 'Sessions revoked.',
            'revoked_count' => $deleted,
        ]);
    }

    private function authorizeSecurityRead(User $authUser, User $targetUser): void
    {
        if ($authUser->username === 'admin' || $authUser->hasAnyRole(RoleNames::adminRoles()) || $authUser->id === $targetUser->id) {
            return;
        }

        if (
            $authUser->hasRole(RoleNames::SUPERVISOR)
            && $authUser->office_id === $targetUser->office_id
            && $authUser->division_id === $targetUser->division_id
        ) {
            return;
        }

        abort(403, 'You are not authorized to view this user security data.');
    }

    private function authorizeSecurityAction(User $authUser, ?User $targetUser = null): void
    {
        if ($authUser->username === 'admin' || $authUser->hasAnyRole(RoleNames::adminRoles())) {
            return;
        }

        if ($targetUser && $authUser->id === $targetUser->id) {
            return;
        }

        abort(403, 'Only administrators or the account owner can manage security actions.');
    }
}
