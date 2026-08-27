<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permIds = DB::table('permissions')
            ->where('name', 'can_share_document')
            ->where('guard_name', 'web')
            ->pluck('id')
            ->toArray();

        if (!empty($permIds)) {
            DB::table('role_has_permissions')
                ->whereIn('permission_id', $permIds)
                ->delete();

            DB::table('permissions')
                ->where('name', 'can_share_document')
                ->where('guard_name', 'web')
                ->delete();
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'name' => 'can_share_document',
            'guard_name' => 'web',
            'group_name' => 'Documents',
            'description' => 'Share a document',
        ]);

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
