<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

Route::post('/login', function (Request $request) {
    $request->validate([
        'username' => 'required',
        'password' => 'required',
    ]);

    $user = User::where('username', $request->username)->first();

    if ($user && Hash::check($request->password, $user->password)) {
        // Create Sanctum Token safely
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => $user,
            'token' => $token
        ], 200);
    }

    return response()->json([
        'message' => 'Invalid username or password'
    ], 401);
});

Route::middleware('auth:sanctum')->group(function () {
    // Return currently logged-in user details
    Route::get('/me', function (Request $request) {
        return response()->json($request->user());
    });

    // Update editable profile fields
    Route::put('/me', function (Request $request) {
        $user = $request->user();

        $validated = $request->validate([
            'fullname' => 'sometimes|nullable|string|max:255',
            'division' => 'sometimes|nullable|string|max:255',
            'position' => 'sometimes|nullable|string|max:255',
            'email'    => 'sometimes|nullable|email|max:255',
        ]);

        $user->update($validated);

        return response()->json($user->fresh());
    });
});