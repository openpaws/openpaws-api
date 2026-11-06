<?php

namespace App\Support;

class ReservedUsernames
{
    public const LIST = [
        'admin', 'api', 'settings', 'upload', 'search', 'browse',
        'login', 'register', 'me', 'openpaws', 'moderator', 'support',
    ];

    public static function contains(string $username): bool
    {
        return in_array(strtolower($username), self::LIST, true);
    }
}
