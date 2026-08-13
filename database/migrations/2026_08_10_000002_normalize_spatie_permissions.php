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
            ['name' => 'can_view_confidential', 'group_name' => 'Documents', 'description' => 'View, share, edit confidential documents within office'],
            ['name' => 'can_edit_document', 'group_name' => 'Documents', 'description' => 'Edit a document'],
            ['name' => 'can_share_document', 'group_name' => 'Documents', 'description' => 'Share a document'],
            ['name' => 'can_transfer_documents', 'group_name' => 'Documents', 'description' => 'Transfer a document'],
            ['name' => 'can_receive_documents', 'group_name' => 'Documents', 'description' => 'Only receive this type of document'],
            ['name' => 'can_register_documents', 'group_name' => 'Documents', 'description' => 'Register a document'],
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
            'super_admin' => [
                'can_view_confidential' => true,
                'can_edit_document' => true,
                'can_share_document' => true,
                'can_transfer_documents' => true,
                'can_receive_documents' => true,
                'can_register_documents' => true,
            ],
            'supervisor' => [
                'can_view_confidential' => true,
                'can_edit_document' => true,
                'can_share_document' => true,
                'can_transfer_documents' => true,
                'can_receive_documents' => true,
                'can_register_documents' => true,
            ],
            'receiver' => [
                'can_view_confidential' => false,
                'can_edit_document' => false,
                'can_share_document' => false,
                'can_transfer_documents' => false,
                'can_receive_documents' => true,
                'can_register_documents' => true,
            ],
        ];

        foreach ($rolePermissions as $roleName => $permissionNames) {
            $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', 'web')->value('id');
            if (! $roleId) {
                continue;
            }

            foreach (array_keys(array_filter($permissionNames)) as $permissionName) {
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
