<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $catalog = [
            'can_archive_document' => ['Documents', ['admin', 'supervisor', 'receiver']],
            'reports.view' => ['Reports', ['admin', 'supervisor']],
            'reports.export' => ['Reports', ['admin', 'supervisor']],
            'users.manage' => ['System Administration', ['admin']],
            'users.permissions.manage' => ['System Administration', ['admin']],
        ];
        foreach ($catalog as $name => [$group, $roles]) {
            $id = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');
            $isNew = ! $id;
            if ($isNew) {
                $id = (string) Str::uuid();
                DB::table('permissions')->insert([
                    'id' => $id, 'name' => $name, 'guard_name' => 'web', 'group_name' => $group,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            // Preserve existing defaults; administrative management must remain available.
            if ($isNew || in_array($name, ['users.manage', 'users.permissions.manage'], true)) {
                foreach (DB::table('roles')->whereIn('name', $roles)->where('guard_name', 'web')->pluck('id') as $roleId) {
                    DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $id]);
                }
            }
        }

        // Preserve legacy direct assignments as explicit user grants, without replacing existing overrides.
        foreach (DB::table('model_has_permissions')->join('permissions', 'permissions.id', '=', 'permission_id')
            ->where('model_type', User::class)->select('model_id', 'permissions.name')->get() as $grant) {
            DB::table('user_permission_overrides')->insertOrIgnore([
                'user_id' => $grant->model_id, 'permission_name' => $grant->name, 'effect' => 'grant',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Preserve permission assignments and user customizations on rollback.
    }
};
