<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $adminRoleId = DB::table('roles')
            ->where('name', 'admin')
            ->where('guard_name', 'web')
            ->value('id');

        if (! $adminRoleId) {
            return;
        }

        $adminUserId = DB::table('users')
            ->where(function ($query) {
                $query->where('username', 'admin')
                    ->orWhere('email', 'admin@example.com');
            })
            ->whereNull('deleted_at')
            ->value('id');

        if (! $adminUserId) {
            return;
        }

        DB::table('model_has_roles')->updateOrInsert(
            [
                'role_id' => $adminRoleId,
                'model_type' => 'App\\Models\\User',
                'model_id' => $adminUserId,
            ],
            []
        );
    }

    public function down(): void
    {
        $adminRoleId = DB::table('roles')
            ->where('name', 'admin')
            ->where('guard_name', 'web')
            ->value('id');

        if (! $adminRoleId) {
            return;
        }

        $adminUserId = DB::table('users')
            ->where(function ($query) {
                $query->where('username', 'admin')
                    ->orWhere('email', 'admin@example.com');
            })
            ->whereNull('deleted_at')
            ->value('id');

        if (! $adminUserId) {
            return;
        }

        DB::table('model_has_roles')
            ->where('role_id', $adminRoleId)
            ->where('model_type', 'App\\Models\\User')
            ->where('model_id', $adminUserId)
            ->delete();
    }
};
