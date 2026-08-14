<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::firstOrCreate(
            ['name' => 'admin', 'guard_name' => 'web'],
            [
                'display_name' => 'Admin',
                'description' => 'Full system access',
                'is_system' => true,
            ]
        );

        $admin = User::updateOrCreate(
            ['username' => 'admin'],
            [
                'password' => Hash::make('1234567'),
                'first_name' => 'System',
                'last_name' => 'Admin',
                'employee_id' => 'ADMIN-000',
                'birthdate' => '1990-01-01',
                'position' => 'Admin',
                'is_active' => true,
                'email' => 'admin@example.com',
            ],
        );

        $admin->syncRoles([$adminRole]);
    }
}
