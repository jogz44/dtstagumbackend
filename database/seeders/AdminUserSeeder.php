<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['username' => 'admin'],
            [
                'password'    => Hash::make('1234567'), 
                'fullname'    => 'System Administrator',
                'employee_id' => 'ADMIN-000',      // Placeholder so database rules pass
                'birthdate'   => '1990-01-01',     // Placeholder so database rules pass
                'office'      => 'Main Office',
                'division'    => 'IT Division',
                'position'    => 'Super Admin',
                'role'        => 'Admin',
                'status'      => 'Active',
            ]
        );
    }
}