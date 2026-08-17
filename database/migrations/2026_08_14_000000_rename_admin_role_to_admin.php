<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $legacyAdminRole = DB::table('roles')
            ->where('name', 'super_admin')
            ->where('guard_name', 'web')
            ->first();

        if (! $legacyAdminRole) {
            return;
        }

        $adminExists = DB::table('roles')
            ->where('name', 'admin')
            ->where('guard_name', 'web')
            ->exists();

        if ($adminExists) {
            DB::table('model_has_roles')
                ->where('role_id', $legacyAdminRole->id)
                ->update(['role_id' => DB::table('roles')->where('name', 'admin')->where('guard_name', 'web')->value('id')]);

            DB::table('role_has_permissions')
                ->where('role_id', $legacyAdminRole->id)
                ->update(['role_id' => DB::table('roles')->where('name', 'admin')->where('guard_name', 'web')->value('id')]);

            DB::table('roles')
                ->where('id', $legacyAdminRole->id)
                ->delete();

            return;
        }

        DB::table('roles')
            ->where('id', $legacyAdminRole->id)
            ->update([
                'name' => 'admin',
                'display_name' => $legacyAdminRole->display_name ?: 'Admin',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        $adminRole = DB::table('roles')
            ->where('name', 'admin')
            ->where('guard_name', 'web')
            ->first();

        if (! $adminRole) {
            return;
        }

        DB::table('roles')
            ->where('id', $adminRole->id)
            ->update([
                'name' => 'admin',
                'display_name' => $adminRole->display_name ?: 'Admin',
                'updated_at' => now(),
            ]);
    }
};
