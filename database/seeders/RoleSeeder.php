<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'name' => 'Administrator',
                'slug' => 'administrator',
                'description' => 'Full system oversight, settings, user lifecycle, and structural management.',
                'is_system' => true,
            ],
            [
                'name' => 'Supervisor',
                'slug' => 'supervisor',
                'description' => 'Division dispatching, approval visibility, and office/division reports.',
                'is_system' => true,
            ],
            [
                'name' => 'Receiver',
                'slug' => 'receiver',
                'description' => 'Front-desk intake, scanning, and immediate routing dispatch.',
                'is_system' => true,
            ],
        ];

        $permissions = [
            ['name' => 'View Dashboard', 'slug' => 'dashboard.view', 'group_name' => 'dashboard'],
            ['name' => 'Manage Users', 'slug' => 'users.manage', 'group_name' => 'users'],
            ['name' => 'Manage Offices', 'slug' => 'offices.manage', 'group_name' => 'structure'],
            ['name' => 'Manage Divisions', 'slug' => 'divisions.manage', 'group_name' => 'structure'],
            ['name' => 'View Reports', 'slug' => 'reports.view', 'group_name' => 'reports'],
            ['name' => 'Approve Documents', 'slug' => 'documents.approve', 'group_name' => 'documents'],
            ['name' => 'Route Documents', 'slug' => 'documents.route', 'group_name' => 'documents'],
            ['name' => 'Receive Documents', 'slug' => 'documents.receive', 'group_name' => 'documents'],
            ['name' => 'Scan Documents', 'slug' => 'documents.scan', 'group_name' => 'documents'],
            ['name' => 'View Sessions', 'slug' => 'sessions.view', 'group_name' => 'security'],
            ['name' => 'Revoke Sessions', 'slug' => 'sessions.revoke', 'group_name' => 'security'],
        ];

        $roleModels = collect($roles)->mapWithKeys(function (array $role) {
            return [
                $role['slug'] => Role::updateOrCreate(
                    ['slug' => $role['slug']],
                    $role
                ),
            ];
        });

        $permissionModels = collect($permissions)->mapWithKeys(function (array $permission) {
            return [
                $permission['slug'] => Permission::updateOrCreate(
                    ['slug' => $permission['slug']],
                    $permission
                ),
            ];
        });

        $rolePermissions = [
            'administrator' => $permissionModels->keys()->all(),
            'supervisor' => [
                'dashboard.view',
                'documents.approve',
                'documents.route',
                'reports.view',
            ],
            'receiver' => [
                'dashboard.view',
                'documents.receive',
                'documents.scan',
                'documents.route',
            ],
        ];

        foreach ($rolePermissions as $roleSlug => $permissionSlugs) {
            $roleModels[$roleSlug]->permissions()->sync(
                $permissionModels->only($permissionSlugs)->pluck('id')->all()
            );
        }
    }
}
