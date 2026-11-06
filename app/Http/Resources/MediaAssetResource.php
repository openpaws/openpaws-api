<?php

namespace App\Http\Resources;

use App\Models\MediaAsset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One media entry: {url, mime, width?, height?, duration_ms?, size_bytes}.
 * Optional fields are omitted when null. The URL comes from MediaUrls.
 *
 * @mixin MediaAsset
 */
class MediaAssetResource extends JsonResource
{
    public function __construct(MediaAsset $resource, private readonly string $url)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return array_filter([
            'url' => $this->url,
            'mime' => $this->mime,
            'width' => $this->width,
            'height' => $this->height,
            'duration_ms' => $this->duration_ms,
            'size_bytes' => (int) $this->size_bytes,
        ], fn ($v) => $v !== null);
    }
}
