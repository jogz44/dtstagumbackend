<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\UserController;
use App\Models\User;
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
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => $user->load(['office', 'division', 'permissionOverrides']),
            'token' => $token,
        ], 200);
    }

    return response()->json([
        'message' => 'Invalid username or password',
    ], 401);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/documents', [DocumentController::class, 'store']);
    
     // Roles
    Route::get('/roles', [\App\Http\Controllers\RoleController::class, 'index']);

    Route::get('/roles/{role}/permissions', [\App\Http\Controllers\RoleController::class, 'getPermissions']);
    Route::put('/roles/{role}/permissions', [\App\Http\Controllers\RoleController::class, 'updatePermissions']);

    Route::get('/organizations', [OrganizationController::class, 'index']);
    Route::apiResource('users', UserController::class);
    // Return currently logged-in user details
    Route::get('/me', function (Request $request) {
        return response()->json($request->user());
    });

    // Update editable profile fields
    Route::put('/me', function (Request $request) {
        $user = $request->user();

        $validated = $request->validate([
            'fullname' => 'sometimes|nullable|string|max:255',
            'office'   => 'sometimes|nullable|string|max:255',
            'division' => 'sometimes|nullable|string|max:255',
            'position' => 'sometimes|nullable|string|max:255',
            'email'    => 'sometimes|nullable|email|max:255',
        ]);

        $user->update($validated);

        return response()->json($user->fresh());
    });

    // Change the authenticated user's password
    Route::post('/change-password', function (Request $request) {
        $request->validate([
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:6',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $user->update(['password' => Hash::make($request->new_password)]);

        return response()->json(['message' => 'Password updated successfully.']);
    });
});
