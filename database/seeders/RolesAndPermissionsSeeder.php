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
            ['name' => 'super_admin', 'guard_name' => 'web', 'display_name' => 'Super Admin', 'description' => 'Full system access', 'is_system' => true],
            ['name' => 'supervisor', 'guard_name' => 'web', 'display_name' => 'Supervisor', 'description' => 'Can oversee and approve documents', 'is_system' => true],
            ['name' => 'receiver', 'guard_name' => 'web', 'display_name' => 'Receiver', 'description' => 'Can receive and route documents', 'is_system' => true],
        ];

        $permissions = [
            ['name' => 'documents.receive', 'guard_name' => 'web', 'group_name' => 'documents', 'description' => 'Receive incoming documents'],
            ['name' => 'documents.transfer', 'guard_name' => 'web', 'group_name' => 'documents', 'description' => 'Transfer documents to another office or division'],
            ['name' => 'documents.view_confidential', 'guard_name' => 'web', 'group_name' => 'documents', 'description' => 'View confidential documents'],
            ['name' => 'documents.approve', 'guard_name' => 'web', 'group_name' => 'documents', 'description' => 'Approve documents'],
            ['name' => 'documents.archive', 'guard_name' => 'web', 'group_name' => 'documents', 'description' => 'Archive documents'],
            ['name' => 'users.create', 'guard_name' => 'web', 'group_name' => 'users', 'description' => 'Create users'],
            ['name' => 'users.edit', 'guard_name' => 'web', 'group_name' => 'users', 'description' => 'Edit users'],
            ['name' => 'users.delete', 'guard_name' => 'web', 'group_name' => 'users', 'description' => 'Delete users'],
            ['name' => 'users.manage_permissions', 'guard_name' => 'web', 'group_name' => 'users', 'description' => 'Manage user permissions'],
        ];

        $roleModels = collect($roles)->mapWithKeys(function (array $role) {
            return [$role['name'] => Role::updateOrCreate(['name' => $role['name'], 'guard_name' => $role['guard_name']], $role)];
        });

        $permissionModels = collect($permissions)->mapWithKeys(function (array $permission) {
            return [$permission['name'] => Permission::updateOrCreate(['name' => $permission['name'], 'guard_name' => $permission['guard_name']], $permission)];
        });

        $roleModels['super_admin']->syncPermissions($permissionModels->keys()->all());
        $roleModels['supervisor']->syncPermissions([
            'documents.transfer',
            'documents.view_confidential',
            'documents.approve',
            'documents.archive',
            'users.create',
            'users.edit',
        ]);
        $roleModels['receiver']->syncPermissions([
            'documents.receive',
            'documents.transfer',
        ]);
    }
}
