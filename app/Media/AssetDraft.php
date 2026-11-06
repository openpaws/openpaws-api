<?php

namespace App\Media;

use App\Enums\AssetRole;

/** A processed file on local disk, waiting to be stored as a media asset. */
final readonly class AssetDraft
{
    public function __construct(
        public AssetRole $role,
        public string $localPath,
        public string $mime,
        public ?int $width = null,
        public ?int $height = null,
        public ?int $durationMs = null,
    ) {}
}
