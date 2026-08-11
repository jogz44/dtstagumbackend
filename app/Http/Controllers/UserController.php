<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::query()
            ->with(['office', 'division', 'roles'])
            ->when($request->filled('office_id'), fn ($query) => $query->where('office_id', (string) $request->input('office_id')))
            ->when($request->filled('division_id'), fn ($query) => $query->where('division_id', (string) $request->input('division_id')))
            ->when($request->filled('role'), fn ($query) => $query->role((string) $request->input('role')))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request, AuditService $audit): JsonResponse
    {
        $user = DB::transaction(function () use ($request, $audit) {
            $validated = $request->validated();
            $temporaryPassword = $this->temporaryPasswordFromBirthdate($validated['birthdate']);
            $validated['password'] = Hash::make($temporaryPassword);
            $validated['must_change_password'] = true;

            $user = User::create($validated);
            $this->syncAuthorizationState($user, $request);
            $user->refresh();

            $audit->accountHistory(
                $user,
                $request->user(),
                'account.created',
                'User account was created.',
                [],
                $request,
            );

            return $user;
        });

        $resource = (new UserResource($user->load(['office', 'division', 'roles'])))->resolve();
        $resource['temporary_password'] = $this->temporaryPasswordFromBirthdate($user->birthdate);

        return response()->json($resource, 201);
    }

    public function update(UpdateUserRequest $request, User $user, AuditService $audit): JsonResponse
    {
        $user = DB::transaction(function () use ($request, $user, $audit) {
            $before = $audit->userSnapshot($user->loadMissing('roles'));
            $validated = $request->validated();

            if (array_key_exists('password', $validated) && filled($validated['password'] ?? null)) {
                $validated['password'] = Hash::make($validated['password']);
            } else {
                unset($validated['password']);
            }

            $user->update($validated);
            $this->syncAuthorizationState($user, $request);
            $user->refresh();

            $changes = $audit->diffSnapshots($before, $audit->userSnapshot($user->loadMissing('roles')));

            if ($changes !== []) {
                $audit->accountHistory(
                    $user,
                    $request->user(),
                    $this->accountUpdateEventType($changes),
                    $this->accountUpdateSummary($changes),
                    $changes,
                    $request,
                );
            }

            return $user;
        });

        return (new UserResource($user->load(['office', 'division', 'roles'])))->response();
    }

    public function destroy(Request $request, User $user, AuditService $audit): JsonResponse
    {
        DB::transaction(function () use ($request, $user, $audit) {
            $user->tokens()->delete();
            $user->delete();

            $audit->accountHistory(
                $user,
                $request->user(),
                'account.deactivated',
                'User account was deactivated.',
                [],
                $request,
            );
        });

        return response()->json(['message' => 'User soft-deleted successfully.']);
    }

    private function temporaryPasswordFromBirthdate(string|Carbon $birthdate): string
    {
        return Carbon::parse($birthdate)->format('mdY');
    }

    private function syncAuthorizationState(User $user, Request $request): void
    {
        if ($request->filled('role')) {
            $user->syncRoles([(string) $request->input('role')]);
        }
    }

    private function accountUpdateEventType(array $changes): string
    {
        if (array_key_exists('role', $changes)) {
            return 'role.assigned';
        }

        if (array_key_exists('office_id', $changes)) {
            return 'office.assigned';
        }

        if (array_key_exists('division_id', $changes)) {
            return 'division.assigned';
        }

        if (array_key_exists('is_active', $changes)) {
            return ($changes['is_active']['after'] ?? false) ? 'account.activated' : 'account.deactivated';
        }

        return 'profile.updated';
    }

    private function accountUpdateSummary(array $changes): string
    {
        $labels = [
            'username' => 'username',
            'first_name' => 'first name',
            'last_name' => 'last name',
            'employee_id' => 'employee ID',
            'birthdate' => 'birthdate',
            'office_id' => 'office',
            'division_id' => 'division',
            'position' => 'position',
            'employment_status' => 'employment status',
            'email' => 'email',
            'is_active' => 'account status',
            'role' => 'role',
        ];

        $changed = collect(array_keys($changes))
            ->map(fn ($field) => $labels[$field] ?? $field)
            ->join(', ');

        return 'Updated '.$changed.'.';
    }
}
