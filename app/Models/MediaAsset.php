<?php

namespace App\Models;

use App\Enums\AssetRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MediaAsset extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'role' => AssetRole::class,
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
