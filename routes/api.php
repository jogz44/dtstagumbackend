<?php

use App\Http\Controllers\AuthController;
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

    $user = User::where('username', $request->username)->first();

    if ($user && Hash::check($request->password, $user->password)) {
        // Revoke all previous tokens so only one active token exists
        $user->tokens()->delete();

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => $user,
            'token' => $token,
        ], 200);
    }

    return response()->json([
        'message' => 'Invalid username or password',
    ], 401);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', function (Request $request) {
        return response()->json($request->user());
    });

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

    // Document Routes
    Route::get('/documents/unopened-count', function (Request $request) {
        // Returns count of documents not yet opened by the current user
        // For now returns 0 — extend when an "opened" tracking table exists
        return response()->json(['count' => 0]);
    });

    Route::get('/documents', function (Request $request) {
        $tab = $request->query('tab', 'my');
        $userId = $request->user()->id;

        $query = \Illuminate\Support\Facades\DB::table('documents')
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc');

        // Filter by tab
        if ($tab === 'completed') {
            $query->where('status', 'Completed');
        } elseif ($tab === 'incoming') {
            $query->where('status', 'Incoming');
        } elseif ($tab === 'released') {
            $query->where('status', 'Released');
        } elseif ($tab === 'transferred') {
            $query->where('status', 'Transferred');
        } else {
            // 'my' tab — show all documents created by this user or all if admin
            // For now return all so the dummy data shows up
            $query->whereIn('status', ['Pending', 'Completed', 'Released', 'Incoming', 'Transferred']);
        }

        $documents = $query->get();
        return response()->json($documents);
    });

    Route::post('/documents', function (Request $request) {
        $user = $request->user();
        $year = date('Y');
        $month = date('m');
        $count = \Illuminate\Support\Facades\DB::table('documents')->count() + 1;
        $trackingNo = 'HRMO-' . $year . '-' . $month . '-' . str_pad($count, 6, '0', STR_PAD_LEFT);

        $id = (string) \Illuminate\Support\Str::uuid();

        \Illuminate\Support\Facades\DB::table('documents')->insert([
            'id'                 => $id,
            'tracking_number'    => $trackingNo,
            'description'        => $request->input('description', $request->input('particular', 'New Document')),
            'destination_office' => $request->input('destination_office', $request->input('office_assignment', '')),
            'document_type'      => $request->input('document_type', 'Transmittal'),
            'priority_level'     => $request->input('priority_level', 'Normal'),
            'status'             => 'Pending',
            'office_assignment'  => $request->input('office_assignment', ''),
            'division_assignment'=> $request->input('division_assignment', ''),
            'landline'           => $request->input('landline', ''),
            'assigned_user'      => $request->input('assigned_user', ''),
            'created_by'         => $user->id,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $document = \Illuminate\Support\Facades\DB::table('documents')->where('id', $id)->first();

        return response()->json([
            'message'        => 'Document created successfully',
            'document'       => $document,
            'tracking_number'=> $trackingNo,
        ], 201);
    });

    // Document Actions
    Route::post('/documents/{id}/action', function (Request $request, $id) {
        $action = $request->input('action');
        $remarks = $request->input('remarks');
        $office = $request->input('office');

        $statusMap = [
            'Complete' => 'Completed',
            'Mark as Complete' => 'Completed',
            'Release' => 'Released',
            'Transfer' => 'Transferred',
            'Return' => 'Returned',
            'Archive' => 'Archived',
            'Retrieve' => 'Pending',
        ];

        $newStatus = $statusMap[$action] ?? 'Pending';
        $updateData = [
            'status' => $newStatus,
            'updated_at' => now(),
        ];

        if ($office) {
            $updateData['destination_office'] = $office;
        }

        \Illuminate\Support\Facades\DB::table('documents')
            ->where('id', $id)
            ->update($updateData);

        $doc = \Illuminate\Support\Facades\DB::table('documents')->where('id', $id)->first();

        return response()->json([
            'message' => "Document updated to {$newStatus}",
            'document' => $doc,
        ]);
    });

    // Organization Routes
    Route::get('/organizations', function (Request $request) {
        return response()->json([
            ['id' => '1', 'name' => 'Human Resource Management Office'],
            ['id' => '2', 'name' => 'City Budget Office'],
            ['id' => '3', 'name' => 'City Administrator Office'],
            ['id' => '4', 'name' => 'Accounting Department'],
            ['id' => '5', 'name' => 'DICT Regional Office'],
            ['id' => '6', 'name' => 'COA Regional Office'],
            ['id' => '7', 'name' => 'DILG Regional Office XI'],
            ['id' => '8', 'name' => 'Sangguniang Panlungsod'],
        ]);
    });

    Route::get('/organizations/users', function (Request $request) {
        return response()->json([]);
    });
});