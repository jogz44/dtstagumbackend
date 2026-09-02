<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            ['name' => 'can_return_document', 'guard_name' => 'web', 'group_name' => 'Documents', 'description' => 'Return a document to sender'],
            ['name' => 'can_release_document', 'guard_name' => 'web', 'group_name' => 'Documents', 'description' => 'Release a document to an office'],
            ['name' => 'can_complete_document', 'guard_name' => 'web', 'group_name' => 'Documents', 'description' => 'Mark a document as completed'],
            ['name' => 'can_archive_document', 'guard_name' => 'web', 'group_name' => 'Documents', 'description' => 'Archive a document'],
        ];

        foreach ($permissions as $perm) {
            DB::table('permissions')->insertOrIgnore($perm);
        }

        $permIds = DB::table('permissions')
            ->whereIn('name', array_column($permissions, 'name'))
            ->where('guard_name', 'web')
            ->pluck('id', 'name')
            ->toArray();

        $roles = DB::table('roles')
            ->where('guard_name', 'web')
            ->pluck('id')
            ->toArray();

        foreach ($roles as $roleId) {
            foreach ($permIds as $permId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permId,
                ]);
            }
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permNames = ['can_return_document', 'can_release_document', 'can_complete_document', 'can_archive_document'];

        $permIds = DB::table('permissions')
            ->whereIn('name', $permNames)
            ->where('guard_name', 'web')
            ->pluck('id')
            ->toArray();

        DB::table('role_has_permissions')->whereIn('permission_id', $permIds)->delete();
        DB::table('permissions')->whereIn('name', $permNames)->where('guard_name', 'web')->delete();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
