<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        if (! $user->birthdate) {
            return response()->json([
                'message' => 'Cannot reset password because this user has no birthdate on file.',
            ], 422);
        }

        $temporaryPassword = Carbon::parse($user->birthdate)->format('mdY');

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

        return response()->json([
            'message' => 'Password reset successfully.',
            'temporary_password' => $temporaryPassword,
        ]);
    }

    public function revokeSession(Request $request, User $user, int $tokenId, AuditService $audit): JsonResponse
    {
        $this->authorizeSecurityAction($request->user());

        $token = $user->tokens()->whereKey($tokenId)->firstOrFail();
        $token->delete();

        $audit->accountHistory(
            $user,
            $request->user(),
            'session.revoked',
            'One active access token was revoked.',
            [],
            $request,
        );

        return response()->json(['message' => 'Session revoked.']);
    }

    public function revokeOtherSessions(Request $request, User $user, AuditService $audit): JsonResponse
    {
        $this->authorizeSecurityAction($request->user());

        $currentTokenId = $request->user()->currentAccessToken()?->id;
        $query = $user->tokens();

        if ($request->user()->id === $user->id && $currentTokenId) {
            $query->whereKeyNot($currentTokenId);
        }

        $deleted = $query->delete();

        $audit->accountHistory(
            $user,
            $request->user(),
            'sessions.revoked_all',
            'Other active access tokens were revoked.',
            [],
            $request,
        );

        return response()->json([
            'message' => 'Sessions revoked.',
            'revoked_count' => $deleted,
        ]);
    }

    private function authorizeSecurityRead(User $authUser, User $targetUser): void
    {
        if ($authUser->hasRole('super_admin') || $authUser->id === $targetUser->id) {
            return;
        }

        if (
            $authUser->hasRole('supervisor')
            && $authUser->office_id === $targetUser->office_id
            && $authUser->division_id === $targetUser->division_id
        ) {
            return;
        }

        abort(403, 'You are not authorized to view this user security data.');
    }

    private function authorizeSecurityAction(User $authUser): void
    {
        if (! $authUser->hasRole('super_admin')) {
            abort(403, 'Only administrators can manage user security.');
        }
    }
}
