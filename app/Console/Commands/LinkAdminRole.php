<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LinkAdminRole extends Command
{
    protected $signature = 'app:link-admin-role
        {--username=admin : Username to use when locating the admin account}
        {--email=admin@example.com : Email to use when locating the admin account}';

    protected $description = 'Ensure the existing admin user is linked to the admin role';

    public function handle(): int
    {
        $role = Role::firstOrCreate(
            ['name' => 'admin', 'guard_name' => 'web'],
            [
                'display_name' => 'Admin',
                'description' => 'Full system access',
                'is_system' => true,
            ]
        );

        $user = User::query()
            ->where('username', (string) $this->option('username'))
            ->orWhere('email', (string) $this->option('email'))
            ->first();

        if (! $user) {
            $this->error('Could not find an admin user using the provided username/email.');

            return self::FAILURE;
        }

        DB::table('model_has_roles')->updateOrInsert(
            [
                'role_id' => $role->id,
                'model_type' => User::class,
                'model_id' => $user->id,
            ],
            []
        );

        $this->info("Linked user {$user->username} ({$user->id}) to role {$role->name}.");

        return self::SUCCESS;
    }
}
