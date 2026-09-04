<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            OfficeDivisionSeeder::class,
            BarangaySeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
