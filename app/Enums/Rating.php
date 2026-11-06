<?php

namespace App\Enums;

enum Rating: string
{
    case General = 'general';
    case Mature = 'mature';
    case Adult = 'adult';

    public function rank(): int
    {
        return match ($this) {
            self::General => 0,
            self::Mature => 1,
            self::Adult => 2,
        };
    }

    /** @return list<Rating> */
    public static function upTo(Rating $max): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (Rating $r) => $r->rank() <= $max->rank(),
        ));
    }
}
