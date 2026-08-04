<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::query()
            ->with(['office', 'division'])
            ->with(['permissionOverrides'])
            ->when($request->filled('office_id'), fn($query) => $query->where('office_id', $request->string('office_id')))
            ->when($request->filled('division_id'), fn($query) => $query->where('division_id', $request->string('division_id')))
            ->when($request->filled('role'), fn($query) => $query->role($request->string('role')))
            ->when($request->filled('is_active'), fn($query) => $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['password'] = Hash::make($validated['password']);

        $user = User::create($validated);

        $this->syncAuthorizationState($user, $request);

        return (new UserResource($user->load(['office', 'division', 'permissionOverrides'])))->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $validated = $request->validated();

        if (array_key_exists('password', $validated) && filled($validated['password'] ?? null)) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);

        $this->syncAuthorizationState($user, $request);

        return (new UserResource($user->load(['office', 'division', 'permissionOverrides'])))->response();
    }

    public function destroy(User $user): JsonResponse
    {
        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'User soft-deleted successfully.']);
    }

    private function syncAuthorizationState(User $user, Request $request): void
    {
        if ($request->filled('role')) {
            $user->syncRoles([$request->string('role')]);
        }

        if ($request->filled('permissions')) {
            $user->syncPermissions($request->input('permissions', []));
        }

        if ($request->has('permission_overrides')) {
            $user->permissionOverrides()->delete();

            foreach ($request->input('permission_overrides', []) as $override) {
                $user->permissionOverrides()->create([
                    'permission_name' => $override['name'],
                    'effect' => $override['effect'],
                ]);
            }

            $allowedOverrides = collect($request->input('permission_overrides', []))
                ->where('effect', 'allow')
                ->pluck('name')
                ->all();

            if ($allowedOverrides !== []) {
                $user->givePermissionTo($allowedOverrides);
            }
        }
    }
}
