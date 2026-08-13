<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['username' => 'admin'],
            [
                'password' => Hash::make('1234567'),
                'first_name' => 'System',
                'last_name' => 'Administrator',
                'employee_id' => 'ADMIN-000',
                'birthdate' => '1990-01-01',
                'position' => 'Super Admin',
                'is_active' => true,
                'email' => 'admin@example.com',
            ],
        );

        $admin->assignRole('admin');
    }
}
