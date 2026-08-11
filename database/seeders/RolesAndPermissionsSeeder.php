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
            ['name' => 'documents.receive', 'guard_name' => 'web', 'group_name' => 'Documents', 'description' => 'Receive incoming documents'],
            ['name' => 'documents.register', 'guard_name' => 'web', 'group_name' => 'Documents', 'description' => 'Register new documents'],
            ['name' => 'documents.edit', 'guard_name' => 'web', 'group_name' => 'Documents', 'description' => 'Edit document metadata and content'],
            ['name' => 'documents.transfer', 'guard_name' => 'web', 'group_name' => 'Documents', 'description' => 'Transfer documents to another office or division'],
            ['name' => 'documents.view_confidential', 'guard_name' => 'web', 'group_name' => 'Documents', 'description' => 'View confidential documents'],
            ['name' => 'users.manage', 'guard_name' => 'web', 'group_name' => 'Administration', 'description' => 'Manage user accounts'],
            ['name' => 'roles.manage', 'guard_name' => 'web', 'group_name' => 'Administration', 'description' => 'Manage roles and permissions'],
            ['name' => 'settings.manage', 'guard_name' => 'web', 'group_name' => 'Administration', 'description' => 'Manage system settings'],
            ['name' => 'reports.view', 'guard_name' => 'web', 'group_name' => 'Reports & Monitoring', 'description' => 'View reports'],
            ['name' => 'activity_logs.view', 'guard_name' => 'web', 'group_name' => 'Reports & Monitoring', 'description' => 'View activity logs'],
            ['name' => 'performance_ranking.view', 'guard_name' => 'web', 'group_name' => 'Reports & Monitoring', 'description' => 'View performance ranking'],
        ];

        $roleModels = collect($roles)->mapWithKeys(function (array $role) {
            return [$role['name'] => Role::updateOrCreate(['name' => $role['name'], 'guard_name' => $role['guard_name']], $role)];
        });

        $permissionModels = collect($permissions)->mapWithKeys(function (array $permission) {
            return [$permission['name'] => Permission::updateOrCreate(['name' => $permission['name'], 'guard_name' => $permission['guard_name']], $permission)];
        });

        $roleModels['super_admin']->syncPermissions($permissionModels->keys()->all());
        $roleModels['supervisor']->syncPermissions([
            'documents.receive',
            'documents.register',
            'documents.transfer',
            'documents.view_confidential',
            'reports.view',
            'activity_logs.view',
            'performance_ranking.view',
        ]);
        $roleModels['receiver']->syncPermissions([
            'documents.receive',
            'documents.register',
            'documents.transfer',
        ]);
    }
}
