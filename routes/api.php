<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AttentionSummaryController;
use App\Http\Controllers\BarangayController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentTrackingController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PmisEmployeeController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\ScanSessionController;
use App\Http\Controllers\UserAuditController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserSecurityController;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditService;
use App\Services\UserInitialPasswordService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'user' => UserResource::make($user->load(['office', 'division', 'permissionOverrides', 'roles'])),
            'must_change_password' => $user->must_change_password,
            'token' => $token,
        ], 200);
    }
    app(AuditService::class)->activity($user, 'auth.login_failed', 'Authentication', 'Failed login attempt.', $request, ['outcome' => 'failure']);

    return response()->json([
        'message' => 'Invalid username or password',
    ], 401);
});

// Public Guest Tracking & Lookup
Route::get('/public/track/{trackingNumber}', [DocumentController::class, 'publicTrack']);
Route::get('/documents/by-tracking/{trackingNumber}', [DocumentController::class, 'getByTracking']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', function (Request $request) {
        $user = $request->user()->load(['roles', 'office', 'division', 'permissions', 'permissionOverrides']);

        return new \App\Http\Resources\UserResource($user);
    });

      Route::post('/logout', function (Request $request) {
        $token = $request->user()->currentAccessToken();
        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        return response()->json(['message' => 'Logged out successfully.']);
    });

    $changePasswordHandler = function (Request $request) {
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:6',
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        if (app(UserInitialPasswordService::class)->matchesUserBirthdate($user, $request->new_password) || ($user->pmisEmployee && app(UserInitialPasswordService::class)->matchesEmployeeBirthday($user->pmisEmployee, $request->new_password))) {
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
    };

    Route::post('/me/change-password', $changePasswordHandler);
    Route::post('/change-password', $changePasswordHandler);

    Route::middleware('password.changed')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'dashboard']);
        Route::get('/dashboard/analytics', [DashboardController::class, 'analytics']);
        Route::get('/pmis-employees', [PmisEmployeeController::class, 'index']);
        Route::get('/activity-logs', [ActivityLogController::class, 'index']);
        Route::get('/reports', [ReportController::class, 'index']);
        Route::get('/documents', [DocumentController::class, 'index']);
        Route::get('/documents/unopened-count', [DocumentController::class, 'unopenedCount']);
        Route::get('/documents/{document}', [DocumentController::class, 'show']);
        Route::post('/documents/{document}/acknowledge', [DocumentController::class, 'acknowledge']);
        Route::post('/documents', [DocumentController::class, 'store']);
        Route::get('/attachments/{attachment}', [DocumentController::class, 'showAttachment']);
        Route::get('/documents/{document}/timeline', [DocumentController::class, 'timeline']);
        Route::get('/documents/{document}/tracking', [DocumentTrackingController::class, 'show']);
        Route::get('/document-tracking', [DocumentTrackingController::class, 'index']);
        Route::get('/document-tracking/statistics', [DocumentTrackingController::class, 'statistics']);
        Route::get('/document-tracking/search', [DocumentTrackingController::class, 'search']);
        Route::put('/documents/{document}', [DocumentController::class, 'update']);
        Route::post('/documents/{document}/transfer', [DocumentController::class, 'transfer']);
        Route::post('/documents/{document}/receive', [DocumentController::class, 'receive']);
        Route::post('/documents/receive-by-code', [DocumentController::class, 'receiveByCode']);
        Route::post('/documents/{document}/release', [DocumentController::class, 'release']);
        Route::post('/documents/{document}/archive', [DocumentController::class, 'archive']);
        Route::post('/documents/{document}/share', [DocumentController::class, 'share']);
        Route::post('/documents/{document}/complete', [DocumentController::class, 'complete']);
        Route::post('/documents/{document}/return', [DocumentController::class, 'returnDocument']);
        Route::post('/documents/{document}/retrieve', [DocumentController::class, 'retrieve']);
        Route::get('/document-types', [DocumentController::class, 'getDocumentTypes']);
        Route::post('/documents/{document}/attachments', [DocumentController::class, 'addAttachment']);
        Route::post('/documents/{document}/sync-scan', [DocumentController::class, 'syncScanSession']);
        Route::delete('/attachments/{attachment}', [DocumentController::class, 'deleteAttachment']);
        Route::post('/documents/{document}/reassign', [DocumentController::class, 'reassign']);
        Route::post('/documents/{document}/force-notify', [DocumentController::class, 'forceNotify']);
        Route::get('/office-users', [DocumentController::class, 'officeUsers']);
        Route::get('/documents/{document}/messages', [DocumentController::class, 'getMessages']);
        Route::post('/documents/{document}/messages', [DocumentController::class, 'sendMessage']);
        Route::get('/organizations', [OrganizationController::class, 'index']);
        Route::get('/organizations/users', [OrganizationController::class, 'getOfficeUsers']);
        Route::get('/roles', [RoleController::class, 'index']);
        Route::get('/roles/options', [RoleController::class, 'options']);
        Route::put('/roles/{role}/permissions', [RoleController::class, 'updatePermissions']);
        Route::get('/barangays', [BarangayController::class, 'index']);
        Route::get('/users/statistics', [UserController::class, 'statistics']);
        Route::post('/users/username-preview', [UserController::class, 'previewUsername']);
        Route::get('/users/{user}/history', [UserAuditController::class, 'history'])->withTrashed();
        Route::get('/users/{user}/transaction-history', [UserAuditController::class, 'transactionHistory'])->withTrashed();
        Route::get('/users/{user}/activity', [UserAuditController::class, 'activity'])->withTrashed();
        Route::get('/users/{user}/security', [UserSecurityController::class, 'show'])->withTrashed();
        Route::post('/users/{user}/reset-password', [UserSecurityController::class, 'resetPassword'])->withTrashed();
        Route::post('/users/{user}/password-reset', [UserSecurityController::class, 'resetPassword'])->withTrashed();
        Route::delete('/users/{user}/sessions/{tokenId}', [UserSecurityController::class, 'revokeSession'])->withTrashed();
        Route::delete('/users/{user}/sessions', [UserSecurityController::class, 'revokeOtherSessions'])->withTrashed();
        Route::patch('/users/{user}/deactivate', [UserController::class, 'deactivate'])->withTrashed();
        Route::patch('/users/{user}/access', [UserController::class, 'updateAccess'])->withTrashed();
        Route::apiResource('users', UserController::class, ['except' => ['destroy']])->withTrashed(['show']);

        Route::get('/me/history', [UserAuditController::class, 'meHistory']);
        Route::get('/me/transaction-history', [UserAuditController::class, 'meTransactionHistory']);
        Route::get('/me/activity', [UserAuditController::class, 'meActivity']);
        Route::get('/me/security', [UserSecurityController::class, 'meShow']);
        Route::delete('/me/sessions/{tokenId}', [UserSecurityController::class, 'meRevokeSession']);
        Route::delete('/me/sessions', [UserSecurityController::class, 'meRevokeOtherSessions']);

        Route::get('/me/attention-summary', AttentionSummaryController::class);
        Route::get('/me/notifications', [NotificationController::class, 'index']);
        Route::get('/me/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/me/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/me/notifications/read-all', [NotificationController::class, 'markAllAsRead']);

        Route::match(['put', 'patch'], '/me', function (Request $request) {
            $user = $request->user();

            $validated = $request->validate([
                'first_name' => 'sometimes|nullable|string|max:255',
                'middle_name' => 'sometimes|nullable|string|max:255',
                'last_name' => 'sometimes|nullable|string|max:255',
                'email' => 'sometimes|nullable|email|max:255|unique:users,email,' . $user->id,
                'contact_number' => 'sometimes|nullable|string|max:50',
                'fullname' => 'sometimes|nullable|string|max:255',
                'office' => 'sometimes|nullable|string|max:255',
                'division' => 'sometimes|nullable|string|max:255',
                'position' => 'sometimes|nullable|string|max:255',
            ]);

            $user->update($validated);

            $user->load(['roles', 'office', 'division']);
            $role = $user->roles->first();
            $user->setAttribute('role_name', $role?->name);
            $user->setAttribute('role', $role);

            return response()->json($user);
        });
    });
});

Route::get('/test-pmis', function () {
    try {
        $pdo = DB::connection('pmis')->getPdo();
        $data = DB::connection('pmis')->table('vwActive')->select('office', 'divisions')->distinct()->limit(5)->get();

        return response()->json([
            'status' => 'success',
            'connection' => 'Connected successfully to SSMS SQL Server!',
            'sample_data' => $data,
        ]);
    } catch (Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ], 500);
    }
});

/*
 * ──────────────────────────────────────────────────────────────────────────────
 *  SCAN SESSION ROUTES  (Public – no authentication required)
 *  These endpoints are polled by the desktop and called by the mobile scanner.
 * ──────────────────────────────────────────────────────────────────────────────
 */
Route::post('/scan-session', [ScanSessionController::class, 'store']);
Route::get('/scan-session/{token}', [ScanSessionController::class, 'show']);
Route::put('/scan-session/{token}/status', [ScanSessionController::class, 'updateStatus']);
Route::post('/scan-session/{token}/pages', [ScanSessionController::class, 'storePage']);
Route::get('/scan-session/{token}/pages/{pageId}/view', [ScanSessionController::class, 'viewPage'])->name('scan-pages.view');
Route::get('/scan-session/{token}/download', [ScanSessionController::class, 'download']);