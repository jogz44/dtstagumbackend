<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PmisEmployeeController;
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
            'user' => new \App\Http\Resources\UserResource($user->load(['office', 'division', 'permissionOverrides', 'roles'])),
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

    Route::post('/logout', function (Request $request) {
        $token = $request->user()->currentAccessToken();
        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        return response()->json(['message' => 'Logged out successfully.']);
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

        if ($user->pmisEmployee && app(\App\Services\UserInitialPasswordService::class)->matchesEmployeeBirthday($user->pmisEmployee, $request->new_password)) {
            return response()->json([
                'message' => 'Choose a password different from your temporary birthday password.',
                'errors' => [
                    'new_password' => ['Choose a password different from your temporary birthday password.'],
                ],
            ], 422);
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
        Route::get('/pmis-employees', [PmisEmployeeController::class, 'index']);
        Route::get('/activity-logs', [ActivityLogController::class, 'index']);
        Route::get('/documents', [DocumentController::class, 'index']);
        Route::post('/documents', [DocumentController::class, 'store']);
        Route::get('/attachments/{attachment}', [DocumentController::class, 'showAttachment']);
        Route::get('/documents/{document}/timeline', [DocumentController::class, 'timeline']);
        Route::put('/documents/{document}', [DocumentController::class, 'update']);
        Route::post('/documents/{document}/transfer', [DocumentController::class, 'transfer']);
        Route::post('/documents/{document}/release', [DocumentController::class, 'release']);
        Route::post('/documents/{document}/archive', [DocumentController::class, 'archive']);
        Route::post('/documents/{document}/share', [DocumentController::class, 'share']);
        Route::post('/documents/{document}/complete', [DocumentController::class, 'complete']);
        Route::post('/documents/{document}/return', [DocumentController::class, 'returnDocument']);
        Route::get('/organizations', [OrganizationController::class, 'index']);
        Route::get('/organizations/users', [OrganizationController::class, 'getOfficeUsers']);
        Route::get('/roles', [\App\Http\Controllers\RoleController::class, 'index']);
        Route::put('/roles/{role}/permissions', [\App\Http\Controllers\RoleController::class, 'updatePermissions']);
        Route::get('/users/{user}/history', [UserAuditController::class, 'history'])->withTrashed();
        Route::get('/users/{user}/transaction-history', [UserAuditController::class, 'transactionHistory'])->withTrashed();
        Route::get('/users/{user}/activity', [UserAuditController::class, 'activity'])->withTrashed();
        Route::get('/users/{user}/security', [UserSecurityController::class, 'show'])->withTrashed();
        Route::post('/users/{user}/password-reset', [UserSecurityController::class, 'resetPassword'])->withTrashed();
        Route::delete('/users/{user}/sessions/{tokenId}', [UserSecurityController::class, 'revokeSession'])->withTrashed();
        Route::delete('/users/{user}/sessions', [UserSecurityController::class, 'revokeOtherSessions'])->withTrashed();
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

Route::get('/test-pmis', function () {
    try {
        $pdo = Illuminate\Support\Facades\DB::connection('pmis')->getPdo();
        $data = Illuminate\Support\Facades\DB::connection('pmis')->table('vwActive')->select('office', 'divisions')->distinct()->limit(5)->get();
        return response()->json([
            'status' => 'success',
            'connection' => 'Connected successfully to SSMS SQL Server!',
            'sample_data' => $data
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
});
