<?php

namespace App\Support;

class PublicId
{
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    public const LENGTH = 10;

    public static function generate(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $id = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $id .= self::ALPHABET[random_int(0, $max)];
        }

        return $id;
    }
}
