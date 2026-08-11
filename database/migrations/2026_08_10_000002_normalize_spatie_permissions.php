<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $permissions = [
            ['name' => 'documents.receive', 'group_name' => 'Documents', 'description' => 'Receive incoming documents'],
            ['name' => 'documents.register', 'group_name' => 'Documents', 'description' => 'Register new documents'],
            ['name' => 'documents.edit', 'group_name' => 'Documents', 'description' => 'Edit document metadata and content'],
            ['name' => 'documents.transfer', 'group_name' => 'Documents', 'description' => 'Transfer documents to another office or division'],
            ['name' => 'documents.view_confidential', 'group_name' => 'Documents', 'description' => 'View confidential documents'],
            ['name' => 'users.manage', 'group_name' => 'Administration', 'description' => 'Manage user accounts'],
            ['name' => 'roles.manage', 'group_name' => 'Administration', 'description' => 'Manage roles and permissions'],
            ['name' => 'settings.manage', 'group_name' => 'Administration', 'description' => 'Manage system settings'],
            ['name' => 'reports.view', 'group_name' => 'Reports & Monitoring', 'description' => 'View reports'],
            ['name' => 'activity_logs.view', 'group_name' => 'Reports & Monitoring', 'description' => 'View activity logs'],
            ['name' => 'performance_ranking.view', 'group_name' => 'Reports & Monitoring', 'description' => 'View performance ranking'],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission['name'], 'guard_name' => 'web'],
                [
                    'id' => DB::table('permissions')->where('name', $permission['name'])->where('guard_name', 'web')->value('id') ?: (string) Str::uuid(),
                    'group_name' => $permission['group_name'],
                    'description' => $permission['description'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $rolePermissions = [
            'super_admin' => collect($permissions)->pluck('name')->all(),
            'supervisor' => [
                'documents.receive',
                'documents.register',
                'documents.transfer',
                'documents.view_confidential',
                'reports.view',
                'activity_logs.view',
                'performance_ranking.view',
            ],
            'receiver' => [
                'documents.receive',
                'documents.register',
                'documents.transfer',
            ],
        ];

        foreach ($rolePermissions as $roleName => $permissionNames) {
            $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', 'web')->value('id');
            if (! $roleId) {
                continue;
            }

            foreach ($permissionNames as $permissionName) {
                $permissionId = DB::table('permissions')->where('name', $permissionName)->where('guard_name', 'web')->value('id');
                if (! $permissionId) {
                    continue;
                }

                DB::table('role_has_permissions')->updateOrInsert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        //
    }
};
