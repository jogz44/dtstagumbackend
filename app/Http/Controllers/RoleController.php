<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Services\EffectivePermissionService;
use App\Support\RoleNames;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoleController extends Controller
{
    /**
     * Display a listing of roles with their aggregated user counts.
     */
    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->with(['permissions' => fn ($query) => $query->orderBy('group_name')->orderBy('name')])
            ->orderBy('display_name')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => $this->rolePayload($role));

        $permissions = Permission::query()
            ->orderBy('group_name')
            ->orderBy('name')
            ->get()
            ->map(fn (Permission $permission) => $this->permissionPayload($permission))
            ->values();

        return response()->json([
            'roles' => $roles,
            'permissions' => $permissions,
        ]);
    }

    public function options(): JsonResponse
    {
        $rolesByName = Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', RoleNames::defaults())
            ->get()
            ->keyBy('name');

        $options = collect(RoleNames::defaults())
            ->map(function (string $roleName) use ($rolesByName): ?array {
                $role = $rolesByName->get($roleName);

                if (! $role) {
                    return null;
                }

                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'label' => RoleNames::label($role->name),
                ];
            })
            ->filter()
            ->values();

        return response()->json($options);
    }

    public function updatePermissions(Request $request, Role $role): JsonResponse
    {
        abort_unless($request->user()->is_active && $request->user()->hasAnyRole(RoleNames::adminRoles()), 403);

        abort_if(
            in_array($role->name, RoleNames::adminRoles(), true),
            403,
            'Administrator role permissions cannot be edited.'
        );

        $validated = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')->where('guard_name', 'web')->whereNull('deleted_at')],
        ]);

        if (in_array($role->name, RoleNames::adminRoles(), true) && array_diff(EffectivePermissionService::ADMIN_PERMISSIONS, $validated['permissions'])) {
            throw ValidationException::withMessages([
                'permissions' => 'Administrator defaults must retain user and permission management access.',
            ]);
        }

        $role->syncPermissions($validated['permissions']);
        $role->load('permissions');

        return response()->json([
            'role' => $this->rolePayload($role),
        ]);
    }

    private function rolePayload(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'display_name' => $role->display_name,
            'description' => $role->description,
            'guard_name' => $role->guard_name,
            'is_system' => $role->is_system,
            'users_count' => $this->usersCount($role),
            'permissions' => $role->permissions
                ->map(fn (Permission $permission) => $this->permissionPayload($permission))
                ->values(),
        ];
    }

    private function permissionPayload(Permission $permission): array
    {
        return [
            'id' => $permission->id,
            'name' => $permission->name,
            'label' => str($permission->name)->replace(['.', '_', '-'], ' ')->title()->toString(),
            'group_name' => $permission->group_name,
            'description' => $permission->description,
        ];
    }

    private function usersCount(Role $role): int
    {
        return DB::table(config('permission.table_names.model_has_roles'))
            ->where('role_id', $role->id)
            ->where('model_type', config('auth.providers.users.model'))
            ->count();
    }
}
