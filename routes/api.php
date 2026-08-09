<?php

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\UserAuditController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserSecurityController;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

Route::post('/login', function (Request $request) {
    $request->validate([
        'username' => 'required',
        'password' => 'required',
    ]);

    $username = trim((string) $request->username);
    $user = User::whereRaw('LOWER(username) = ?', [strtolower($username)])->first();

    if ($user && Hash::check($request->password, $user->password)) {
        if (! $user->is_active) {
            return response()->json([
                'message' => 'This account is inactive. Please contact an administrator.',
            ], 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        app(AuditService::class)->activity($user, 'auth.login_succeeded', 'Authentication', 'Logged in to the system.', $request);
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => $user->load(['office', 'division', 'permissionOverrides']),
            'must_change_password' => $user->must_change_password,
            'token' => $token,
        ], 200);
    }
    app(AuditService::class)->activity($user, 'auth.login_failed', 'Authentication', 'Failed login attempt.', $request, ['outcome' => 'failure']);

    return response()->json([
        'message' => 'Invalid username or password',
    ], 401);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', function (Request $request) {
        return response()->json($request->user());
    });

    Route::post('/change-password', function (Request $request) {
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:6',
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $user->update([
            'password' => Hash::make($request->new_password),
            'must_change_password' => false,
        ]);

        app(AuditService::class)->accountHistory($user, $user, 'password.changed', 'Password was changed by the account owner.', [], $request);

        return response()->json([
            'message' => 'Password updated successfully.',
            'must_change_password' => false,
        ]);
    });

    Route::middleware('password.changed')->group(function () {
        Route::post('/documents', [DocumentController::class, 'store']);
        Route::get('/organizations', [OrganizationController::class, 'index']);
        Route::get('/users/{user}/history', [UserAuditController::class, 'history']);
        Route::get('/users/{user}/activity', [UserAuditController::class, 'activity']);
        Route::get('/users/{user}/security', [UserSecurityController::class, 'show']);
        Route::post('/users/{user}/password-reset', [UserSecurityController::class, 'resetPassword']);
        Route::delete('/users/{user}/sessions/{tokenId}', [UserSecurityController::class, 'revokeSession']);
        Route::delete('/users/{user}/sessions', [UserSecurityController::class, 'revokeOtherSessions']);
        Route::apiResource('users', UserController::class);

        Route::put('/me', function (Request $request) {
            $user = $request->user();

            $validated = $request->validate([
                'fullname' => 'sometimes|nullable|string|max:255',
                'office' => 'sometimes|nullable|string|max:255',
                'division' => 'sometimes|nullable|string|max:255',
                'position' => 'sometimes|nullable|string|max:255',
                'email' => 'sometimes|nullable|email|max:255',
            ]);

            $user->update($validated);

            return response()->json($user->fresh());
        });
    });
});
