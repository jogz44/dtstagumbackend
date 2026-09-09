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

        $supervisorRole = Role::firstOrCreate(['name' => 'supervisor', 'guard_name' => 'web']);
        $supervisor = User::updateOrCreate(
            ['username' => 'supervisor'],
            [
                'password' => Hash::make('1234567'),
                'first_name' => 'Maria',
                'last_name' => 'Santos',
                'employee_id' => 'SUP-001',
                'birthdate' => '1992-05-15',
                'position' => 'Supervisor',
                'is_active' => true,
                'email' => 'supervisor@example.com',
            ],
        );
        $supervisor->syncRoles([$supervisorRole]);

        $receiverRole = Role::firstOrCreate(['name' => 'receiver', 'guard_name' => 'web']);
        $receiver = User::updateOrCreate(
            ['username' => 'receiver'],
            [
                'password' => Hash::make('1234567'),
                'first_name' => 'Juan',
                'last_name' => 'Dela Cruz',
                'employee_id' => 'REC-001',
                'birthdate' => '1995-08-20',
                'position' => 'Receiver',
                'is_active' => true,
                'email' => 'receiver@example.com',
            ],
        );
        $receiver->syncRoles([$receiverRole]);
    }
}
