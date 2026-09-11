<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => 'admin', 'guard_name' => 'web', 'display_name' => 'Administrator', 'description' => 'Full system access', 'is_system' => true],
            ['name' => 'supervisor', 'guard_name' => 'web', 'display_name' => 'Supervisor', 'description' => 'Can transfer documents', 'is_system' => true],
            ['name' => 'receiver', 'guard_name' => 'web', 'display_name' => 'Receiver', 'description' => 'Can receive documents', 'is_system' => true],
        ];

        $permissions = [
            ['name' => 'can_view_confidential', 'guard_name' => 'web', 'group_name' => 'Documents', 'description' => 'View, share, edit confidential documents within office'],
            ['name' => 'can_edit_document', 'guard_name' => 'web', 'group_name' => 'Documents', 'description' => 'Edit a document'],
            ['name' => 'can_share_document', 'guard_name' => 'web', 'group_name' => 'Documents', 'description' => 'Share a document'],
            ['name' => 'can_transfer_documents', 'guard_name' => 'web', 'group_name' => 'Documents', 'description' => 'Transfer a document'],
            ['name' => 'can_receive_documents', 'guard_name' => 'web', 'group_name' => 'Documents', 'description' => 'Only receive this type of document'],
            ['name' => 'can_add_documents', 'guard_name' => 'web', 'group_name' => 'Documents', 'description' => 'Register a document'],
            ['name' => 'users.manage', 'guard_name' => 'web', 'group_name' => 'Users', 'description' => 'Manage user accounts'],
        ];

        $roleModels = collect($roles)->mapWithKeys(function (array $role) {
            return [$role['name'] => Role::updateOrCreate(['name' => $role['name'], 'guard_name' => $role['guard_name']], $role)];
        });

        $permissions = array_merge($permissions, [
            ['name' => 'can_release_document', 'guard_name' => 'web', 'group_name' => 'Documents'],
            ['name' => 'can_complete_document', 'guard_name' => 'web', 'group_name' => 'Documents', 'description' => 'Mark a document as completed'],
            ['name' => 'can_return_document', 'guard_name' => 'web', 'group_name' => 'Documents', 'description' => 'Return a document to sender'],
            ['name' => 'can_archive_document', 'guard_name' => 'web', 'group_name' => 'Documents'],
            ['name' => 'reports.view', 'guard_name' => 'web', 'group_name' => 'Reports'],
            ['name' => 'reports.export', 'guard_name' => 'web', 'group_name' => 'Reports'],
            ['name' => 'performance_ranking.view', 'guard_name' => 'web', 'group_name' => 'Performance', 'description' => 'View performance ranking'],
            ['name' => 'users.permissions.manage', 'guard_name' => 'web', 'group_name' => 'System Administration'],
        ]);

        $permissionModels = collect($permissions)->mapWithKeys(function (array $permission) {
            return [$permission['name'] => Permission::updateOrCreate(['name' => $permission['name'], 'guard_name' => $permission['guard_name']], $permission)];
        });

        $roleModels['admin']->syncPermissions(array_column($permissions, 'name'));
        $roleModels['supervisor']->syncPermissions(array_values(array_diff(array_column($permissions, 'name'), ['users.manage', 'users.permissions.manage'])));
        $roleModels['receiver']->syncPermissions([
            'can_receive_documents',
            'can_add_documents',
            'can_edit_document',
            'can_transfer_documents',
            'can_release_document',
            'can_complete_document',
            'can_return_document',
            'can_archive_document',
            'performance_ranking.view',
            'reports.view',
        ]);

        // Give reports.view to all remaining roles in database if any exist
        $reportsViewPermission = Permission::findOrCreate('reports.view', 'web');
        foreach (Role::all() as $r) {
            $r->givePermissionTo($reportsViewPermission);
        }
    }
}
