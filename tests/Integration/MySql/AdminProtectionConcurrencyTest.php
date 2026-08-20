<?php

namespace Tests\Integration\MySql;

use App\Models\Role;
use App\Models\User;
use App\Services\UserAccessService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class AdminProtectionConcurrencyTest extends TestCase
{
    #[Group('mysql')]
    public function test_simultaneous_admin_demotions_cannot_remove_every_active_administrator(): void
    {
        if (config('database.default') !== 'mysql' || ! env('RUN_MYSQL_CONCURRENCY_TESTS', false)) {
            $this->markTestSkipped('Run with a dedicated MySQL test database and RUN_MYSQL_CONCURRENCY_TESTS=true.');
        }

        $databaseName = (string) config('database.connections.mysql.database');
        $this->assertTrue(
            Str::contains(Str::lower($databaseName), ['test', 'testing']),
            'The MySQL concurrency suite must only run against a dedicated test database.',
        );

        $adminRole = Role::create([
            'name' => 'admin',
            'guard_name' => 'web',
            'display_name' => 'Administrator',
            'is_system' => true,
        ]);
        $receiverRole = Role::create([
            'name' => 'receiver',
            'guard_name' => 'web',
            'display_name' => 'Receiver',
            'is_system' => true,
        ]);

        $actor = User::create([
            'username' => 'concurrency.actor',
            'password' => bcrypt('password'),
            'first_name' => 'Concurrency',
            'last_name' => 'Actor',
            'is_active' => true,
        ]);
        $actor->assignRole($receiverRole);

        $administrators = collect(['first', 'second'])->map(function (string $suffix) use ($adminRole): User {
            $user = User::create([
                'username' => "concurrency.admin.{$suffix}",
                'password' => bcrypt('password'),
                'first_name' => 'Concurrency',
                'last_name' => ucfirst($suffix),
                'is_active' => true,
            ]);
            $user->assignRole($adminRole);

            return $user;
        });

        $actorId = $actor->id;
        $firstAdministratorId = $administrators[0]->id;
        $secondAdministratorId = $administrators[1]->id;
        $receiverRoleId = $receiverRole->id;

        [$firstResult, $secondResult] = Concurrency::driver('process')->run([
            fn () => self::attemptDemotion(
                $actorId,
                $firstAdministratorId,
                $receiverRoleId,
            ),
            fn () => self::attemptDemotion(
                $actorId,
                $secondAdministratorId,
                $receiverRoleId,
            ),
        ]);

        $this->assertSame([200, 422], collect([$firstResult, $secondResult])->sort()->values()->all());
        $this->assertSame(1, User::role('admin')->where('is_active', true)->count());
    }

    private static function attemptDemotion(string $actorId, string $targetId, string $receiverRoleId): int
    {
        try {
            app(UserAccessService::class)->update(
                User::findOrFail($actorId),
                User::findOrFail($targetId),
                ['role_id' => $receiverRoleId],
                Request::create("/api/users/{$targetId}/access", 'PATCH'),
            );

            return 200;
        } catch (HttpResponseException $exception) {
            return $exception->getResponse()->getStatusCode();
        }
    }
}
