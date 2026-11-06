<?php

namespace App\Models\Concerns;

use App\Support\PublicId;
use RuntimeException;

/** Fills `public_id` with a random short id on create and routes by it. */
trait HasPublicId
{
    private const PUBLIC_ID_ATTEMPTS = 5;

    public static function bootHasPublicId(): void
    {
        static::creating(function ($model) {
            if (! empty($model->public_id)) {
                return;
            }
            for ($i = 0; $i < self::PUBLIC_ID_ATTEMPTS; $i++) {
                $id = PublicId::generate();
                if (! static::withoutGlobalScopes()->where('public_id', $id)->exists()) {
                    $model->public_id = $id;

                    return;
                }
            }
            throw new RuntimeException('Could not generate a unique public id.');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
