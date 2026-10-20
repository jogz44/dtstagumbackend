<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserAccessRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserAccessResource;
use App\Http\Resources\UserResource;
use App\Models\Division;
use App\Models\Office;
use App\Models\PmisEmployee;
use App\Models\User;
use App\Services\AuditService;
use App\Services\UserAccessService;
use App\Services\UserAccountService;
use App\Services\UserDeactivationService;
use App\Services\UserInitialPasswordService;
use App\Support\RoleNames;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function previewUsername(Request $request, UserAccountService $userAccountService): JsonResponse
    {
        $this->authorizeUserManagement($request);

        $request->validate([
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'firstName' => 'nullable|string|max:100',
            'lastName' => 'nullable|string|max:100',
        ]);

        $firstName = (string) ($request->input('first_name') ?? $request->input('firstName', ''));
        $lastName = (string) ($request->input('last_name') ?? $request->input('lastName', ''));

        $username = $userAccountService->generateUsername($firstName, $lastName);

        return response()->json([
            'username' => $username,
        ]);
    }

    // public function statistics(Request $request): JsonResponse
    // {
    //     $activeCount = User::query()->where('is_active', true)->count();
    //     $inactiveNormalCount = User::query()->where('is_active', false)->count();
    //     $deactivatedCount = User::onlyTrashed()->count();
    //     $totalCount = User::withTrashed()->count();

    //     return response()->json([
    //         'total_users' => $totalCount,
    //         'active_users' => $activeCount,
    //         'inactive_users' => $inactiveNormalCount + $deactivatedCount,
    //         'breakdown' => [
    //             'active' => $activeCount,
    //             'inactive' => $inactiveNormalCount,
    //             'deactivated' => $deactivatedCount,
    //         ],
    //     ]);
    // }
    public function statistics(Request $request): JsonResponse
    {
        $officeId = $request->user()->office_id;

        $scope = fn($q) => $officeId === null
            ? $q->whereRaw('1 = 0')
            : $q->where('office_id', $officeId);

        $activeCount = $scope(User::query()->where('is_active', true))->count();
        $inactiveNormalCount = $scope(User::query()->where('is_active', false))->count();
        $deactivatedCount = $scope(User::onlyTrashed())->count();
        $totalCount = $scope(User::withTrashed())->count();

        return response()->json([
            'total_users' => $totalCount,
            'active_users' => $activeCount,
            'inactive_users' => $inactiveNormalCount + $deactivatedCount,
            'breakdown' => [
                'active' => $activeCount,
                'inactive' => $inactiveNormalCount,
                'deactivated' => $deactivatedCount,
            ],
        ]);
    }

    public function index(Request $request)
    {
        $allowed = [25, 50, 100];
        $perPage = in_array($request->integer('per_page'), $allowed, true)
            ? $request->integer('per_page')
            : 25;

        if ($request->filled('status')) {
            $status = strtolower((string) $request->input('status'));
        } elseif ($request->filled('is_active')) {
            $isActive = filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $status = $isActive === false ? 'inactive' : 'active';
        } else {
            $status = 'all';
        }

        if (! in_array($status, ['all', 'active', 'inactive', 'deactivated'], true)) {
            $status = 'all';
        }

        switch ($status) {
            case 'active':
                $query = User::query()->where('is_active', true);
                break;

            case 'inactive':
            case 'deactivated':
                $query = User::withTrashed()
                    ->where(function ($query) {
                        $query->where(function ($q) {
                            $q->whereNull('deleted_at')
                                ->where('is_active', false);
                        })->orWhereNotNull('deleted_at');
                    });
                break;

            case 'all':
            default:
                $query = User::withTrashed();
                break;
        }


        $authUser = $request->user();


        if ($authUser->office_id === null) {
            $query->whereRaw('1 = 0'); // no office assigned, show nothing
        } else {
            $query->where('office_id', $authUser->office_id);
        }

        $query->with([
            'office:id,name,short_name',
            'division:id,name,short_name,office_id',
            'barangay:id,name,city',
            'roles:id,name,display_name,description',
        ]);

        $users = $query
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim((string) $request->input('search'));
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'LIKE', "%{$search}%")
                        ->orWhere('last_name', 'LIKE', "%{$search}%")
                        ->orWhereRaw("(first_name || ' ' || last_name) LIKE ?", ["%{$search}%"])
                        ->orWhere('employee_id', 'LIKE', "%{$search}%")
                        ->orWhere('username', 'LIKE', "%{$search}%")
                        ->orWhere('email', 'LIKE', "%{$search}%")
                        ->orWhere('position', 'LIKE', "%{$search}%");
                });
            })
            ->when($request->filled('office_id'), fn ($query) => $query->where('office_id', (string) $request->input('office_id')))
            ->when($request->filled('division_id'), fn ($query) => $query->where('division_id', (string) $request->input('division_id')))
            ->when($request->filled('role'), fn ($query) => $query->role((string) $request->input('role')))
            ->latest()
            ->paginate($perPage);

        return UserResource::collection($users);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorizeUserView($request->user(), $user);
        $user->loadMissing(['office', 'division', 'barangay', 'roles', 'pmisEmployee', 'permissionOverrides']);

        return (new UserAccessResource($user))->response();
    }

    public function store(StoreUserRequest $request, UserAccountService $userAccountService): JsonResponse
    {
        $this->authorizeUserManagement($request);

        $user = $userAccountService->createUser($request->validated(), $request->user(), $request);

        $resource = (new UserResource($user->load(['office', 'division', 'barangay', 'roles', 'pmisEmployee'])))->resolve();

        return response()->json($resource, 201);
    }

    public function update(UpdateUserRequest $request, User $user, AuditService $audit): JsonResponse
    {
        $validated = $request->validated();

        $updates = [];

        if (array_key_exists('username', $validated)) {
            $updates['username'] = $validated['username'];
        }

        if (array_key_exists('is_active', $validated)) {
            if ($this->isProtectedAccount($user) && $validated['is_active'] === false) {
                abort(response()->json([
                    'message' => 'This account cannot be deactivated.',
                ], 422));
            }

            if (! $this->isProtectedAccount($user)) {
                $updates['is_active'] = $validated['is_active'];
            }
        }

        if ($updates === []) {
            return (new UserResource($user->load(['office', 'division', 'roles'])))->response();
        }

        $user = DB::transaction(function () use ($request, $user, $audit, $updates) {
            $before = $audit->userSnapshot($user->loadMissing('roles'));

            $user->update($updates);
            $user->refresh();

            $changes = $audit->diffSnapshots($before, $audit->userSnapshot($user->loadMissing('roles')));

            if ($changes !== []) {
                $eventType = $this->accountUpdateEventType($changes);

                $audit->accountHistory(
                    $user,
                    $request->user(),
                    $eventType,
                    $this->accountUpdateSummary($changes),
                    $changes,
                    $request,
                );

                $audit->activity(
                    $request->user(),
                    $eventType,
                    'User Management',
                    "Updated profile information for {$user->first_name} {$user->last_name}.",
                    $request,
                    [
                        'resource_type' => 'User',
                        'resource_id' => $user->id,
                    ],
                );
            }

            return $user;
        });

        return (new UserResource($user->load(['office', 'division', 'roles'])))->response();
    }

    public function updateAccess(
        UpdateUserAccessRequest $request,
        User $user,
        UserAccessService $userAccess,
    ): JsonResponse {
        $updatedUser = $userAccess->update(
            $request->user(),
            $user,
            $request->validated(),
            $request,
        );

        return (new UserAccessResource($updatedUser))->response();
    }

    private function isProtectedAccount(User $user): bool
    {
        return $user->hasAnyRole(RoleNames::adminRoles());
    }




    public function deactivate(
        Request $request,
        User $user,
        UserDeactivationService $deactivationService,
        AuditService $audit
    ): JsonResponse {
        $authUser = $request->user();

        if ($authUser->username !== 'admin' && ! $authUser->hasAnyRole(RoleNames::adminRoles()) && ! $authUser->hasPermissionTo('users.manage')) {
            abort(403, 'You are not authorized to manage user accounts.');
        }

        if ($authUser->id === $user->id) {
            return response()->json([
                'message' => 'You cannot deactivate your own account.',
            ], 422);
        }

        if ($user->hasAnyRole(RoleNames::adminRoles())) {
            abort(403, 'Administrator accounts cannot be edited by another administrator.');
        }

        if ($user->trashed() || ! $user->is_active) {
            return response()->json([
                'message' => 'This account is already deactivated.',
            ], 422);
        }

        $deactivationService->deactivate($user, $authUser, $audit, $request);

        return response()->json([
            'message' => 'User account access has been removed.',
        ]);
    }

    private function temporaryPasswordFromBirthdate(string|Carbon $birthdate): string
    {
        return Carbon::parse($birthdate)->format('mdY');
    }

    private function syncAuthorizationState(User $user, Request $request): void
    {
        // Protected admin roles cannot be modified via user updates
        if ($user->exists && ($user->hasRole('admin') || $user->hasRole(RoleNames::ADMIN))) {
            return;
        }

        $role = $request->filled('role')
            ? (string) $request->input('role')
            : $this->defaultRoleName();

        if ($role !== null) {
            $user->syncRoles([$role]);
        }
    }

    private function defaultRoleName(): ?string
    {
        if (DB::table('roles')->where('name', RoleNames::RECEIVER)->exists()) {
            return RoleNames::RECEIVER;
        }

        if (DB::table('roles')->where('name', RoleNames::SUPERVISOR)->exists()) {
            return RoleNames::SUPERVISOR;
        }

        return null;
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

    private function generateUsername(PmisEmployee $employee): string
    {
        $firstName = trim((string) $employee->first_name);
        $lastName = trim((string) $employee->last_name);

        $firstInitial = $firstName !== '' ? mb_substr($firstName, 0, 1) : 'e';
        $rawCandidate = strtolower($firstInitial.$lastName);
        $baseUsername = preg_replace('/[^a-z0-9]/', '', $rawCandidate);

        if ($baseUsername === '') {
            $baseUsername = 'emp'.preg_replace('/[^0-9]/', '', (string) $employee->pmis_no);
        }

        $username = $baseUsername;
        $suffix = 2;

        while (User::withTrashed()->whereRaw('LOWER(username) = ?', [strtolower($username)])->exists()) {
            $username = $baseUsername.$suffix;
            $suffix++;
        }

        return $username;
    }

    private function resolveOfficeId(?string $pmisOffice): ?string
    {
        if ($pmisOffice === null || trim($pmisOffice) === '') {
            return null;
        }

        $normalized = preg_replace('/\s+/', ' ', strtolower(trim($pmisOffice)));

        $office = Office::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])
            ->orWhereRaw('LOWER(TRIM(short_name)) = ?', [$normalized])
            ->first();

        return $office?->id;
    }

    private function resolveDivisionId(?string $officeId, ?string $pmisDivision): ?string
    {
        if ($officeId === null || $pmisDivision === null || trim($pmisDivision) === '') {
            return null;
        }

        $normalized = preg_replace('/\s+/', ' ', strtolower(trim($pmisDivision)));

        $division = Division::query()
            ->where('office_id', $officeId)
            ->where(function ($query) use ($normalized) {
                $query->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])
                    ->orWhereRaw('LOWER(TRIM(short_name)) = ?', [$normalized]);
            })
            ->first();

        return $division?->id;
    }

    private function authorizeUserManagement(Request $request): void
    {
        $authUser = $request->user();

        if (! $authUser) {
            abort(401, 'Unauthenticated.');
        }

        if ($authUser->username === 'admin' || $authUser->hasAnyRole(RoleNames::adminRoles())) {
            return;
        }

        try {
            if ($authUser->hasPermissionTo('users.manage') || $authUser->hasPermissionTo('users.create')) {
                return;
            }
        } catch (\Throwable) {
            // Missing optional permission
        }

        abort(403, 'You are not authorized to manage user accounts.');
    }

    private function authorizeUserView(User $actor, User $targetUser): void
    {
        if ($actor->id === $targetUser->id || $actor->username === 'admin' || $actor->hasAnyRole(RoleNames::adminRoles())) {
            return;
        }

        try {
            if ($actor->hasPermissionTo('users.manage') || $actor->hasPermissionTo('users.view')) {
                return;
            }
        } catch (\Throwable) {
            // A missing optional permission does not grant access.
        }

        abort(403, 'You are not authorized to view this user.');
    }
}
