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
            ['name' => 'admin', 'guard_name' => 'web', 'display_name' => 'Admin', 'description' => 'Full system access', 'is_system' => true],
            ['name' => 'supervisor', 'guard_name' => 'web', 'display_name' => 'Supervisor', 'description' => 'Can transfer documents', 'is_system' => true],
            ['name' => 'receiver', 'guard_name' => 'web', 'display_name' => 'Receiver', 'description' => 'Can receive documents', 'is_system' => true],
        ];

        $permissions = [
            ['name' => 'can_view_confidential', 'guard_name' => 'web', 'group_name' => 'Documents', 'description' => 'View, share, edit confidential documents within office'],
            ['name' => 'can_edit_document', 'guard_name' => 'web', 'group_name' => 'Documents', 'description' => 'Edit a document'],
            ['name' => 'can_share_document', 'guard_name' => 'web', 'group_name' => 'Documents', 'description' => 'Share a document'],
            ['name' => 'can_transfer_documents', 'guard_name' => 'web', 'group_name' => 'Documents', 'description' => 'Transfer a document'],
            ['name' => 'can_receive_documents', 'guard_name' => 'web', 'group_name' => 'Documents', 'description' => 'Only receive this type of document'],
            ['name' => 'can_register_documents', 'guard_name' => 'web', 'group_name' => 'Documents', 'description' => 'Register a document'],
            ['name' => 'users.manage', 'guard_name' => 'web', 'group_name' => 'Users', 'description' => 'Manage user accounts'],
        ];

        $roleModels = collect($roles)->mapWithKeys(function (array $role) {
            return [$role['name'] => Role::updateOrCreate(['name' => $role['name'], 'guard_name' => $role['guard_name']], $role)];
        });

        $permissionModels = collect($permissions)->mapWithKeys(function (array $permission) {
            return [$permission['name'] => Permission::updateOrCreate(['name' => $permission['name'], 'guard_name' => $permission['guard_name']], $permission)];
        });

        $roleModels['admin']->syncPermissions(array_column($permissions, 'name'));
        $roleModels['supervisor']->syncPermissions(array_column($permissions, 'name'));
        $roleModels['receiver']->syncPermissions(['can_receive_documents', 'can_register_documents']);
    }
}
