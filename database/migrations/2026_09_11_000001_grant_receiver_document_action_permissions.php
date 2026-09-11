<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('name', 'receiver')->where('guard_name', 'web')->value('id');
        if (! $roleId) {
            return;
        }

        $permissionNames = [
            'can_edit_document',
            'can_transfer_documents',
            'can_release_document',
            'can_complete_document',
            'can_return_document',
            'can_archive_document',
        ];

        foreach ($permissionNames as $permissionName) {
            $permissionId = DB::table('permissions')->where('name', $permissionName)->where('guard_name', 'web')->value('id');
            if ($permissionId) {
                DB::table('role_has_permissions')->updateOrInsert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        //
    }
};
