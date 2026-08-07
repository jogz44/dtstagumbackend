<?php
namespace App\Http\Controllers;
use App\Models\Role;
use App\Http\Resources\RolePermissionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class RoleController extends Controller
{
    /**
     * Display a listing of roles with their aggregated user counts.
     */
    public function index(): JsonResponse
    {
        $roles = Role::withCount('users')->get();
        return response()->json([
            'roles' => $roles
        ]);
    }
    /**
     * Get permissions for a specific role.
     */
    public function getPermissions(Role $role): JsonResponse
    {
        // Use the Eloquent Resource to map dynamic strings to strict boolean keys
        return response()->json(new RolePermissionResource($role->load('permissions')));
    }
    /**
     * Update permissions for a specific role.
     */
    public function updatePermissions(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'permissions' => 'required|array',
            'permissions.can_receive_documents' => 'boolean',
            'permissions.can_transfer_documents' => 'boolean',
            'permissions.can_view_confidential' => 'boolean',
            'permissions.can_edit_document' => 'boolean',
            'permissions.can_share_document' => 'boolean',
        ]);
        $permissionsToSync = [];
        
        foreach ($validated['permissions'] as $permissionName => $isGranted) {
            if ($isGranted) {
                $permissionsToSync[] = $permissionName;
            }
        }
        DB::transaction(function () use ($role, $permissionsToSync) {
            $role->syncPermissions($permissionsToSync);
        });
        return response()->json([
            'message' => 'Permissions updated successfully.',
            'data' => new RolePermissionResource($role->load('permissions'))
        ]);
    }
}