<?php

namespace App\Support;

final class RoleNames
{
    public const ADMIN = 'super_admin';
    public const SUPERVISOR = 'supervisor';
    public const RECEIVER = 'receiver';

    public static function defaults(): array
    {
        return [
            self::ADMIN,
            self::SUPERVISOR,
            self::RECEIVER,
        ];
    }
}
