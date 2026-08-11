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
        return response()->json($request->user()->load(['office', 'division']));
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
        Route::get('/documents', [DocumentController::class, 'index']);
        Route::get('/organizations', [OrganizationController::class, 'index']);
        Route::get('/organizations/users', [OrganizationController::class, 'getOfficeUsers']);
        Route::get('/roles', [\App\Http\Controllers\RoleController::class, 'index']);
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

            // 1. Resolve first name and last name from fullname
            if (isset($validated['fullname'])) {
                $parts = explode(' ', trim($validated['fullname']));
                if (count($parts) > 1) {
                    $user->last_name = array_pop($parts);
                    $user->first_name = implode(' ', $parts);
                } else {
                    $user->first_name = $validated['fullname'];
                    $user->last_name = '';
                }
            }

            // 2. Resolve Office ID
            if (isset($validated['office'])) {
                $officeName = trim($validated['office']);
                if (empty($officeName) || $officeName === 'N/A' || $officeName === '—') {
                    $user->office_id = null;
                } else {
                    $office = \App\Models\Office::firstOrCreate(
                        ['name' => $officeName],
                        [
                            'code' => strtoupper(\Illuminate\Support\Str::slug($officeName)) . '-' . substr(md5($officeName), 0, 5),
                            'short_name' => $officeName,
                            'is_active' => true,
                        ]
                    );
                    $user->office_id = $office->id;
                }
            }

            // 3. Resolve Division ID
            if (isset($validated['division'])) {
                $divisionName = trim($validated['division']);
                if (empty($divisionName) || $divisionName === 'N/A' || $divisionName === '—') {
                    $user->division_id = null;
                } else {
                    $division = \App\Models\Division::firstOrCreate(
                        [
                            'office_id' => $user->office_id,
                            'name' => $divisionName,
                        ],
                        [
                            'code' => strtoupper(\Illuminate\Support\Str::slug($divisionName)) . '-' . substr(md5($divisionName), 0, 5),
                            'short_name' => $divisionName,
                            'is_active' => true,
                        ]
                    );
                    $user->division_id = $division->id;
                }
            }

            // 4. Update position & email
            if (isset($validated['position'])) {
                $user->position = $validated['position'];
            }
            if (isset($validated['email'])) {
                $user->email = $validated['email'];
            }

            $user->save();

            return response()->json($user->load(['office', 'division']));
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
