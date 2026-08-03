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
                'password' => Hash::make('1234567'), 
                'fullname' => 'System Administrator',
                'role'     => 'Admin',
                'status'   => 'Active',
              
            ]
        );
    }
}