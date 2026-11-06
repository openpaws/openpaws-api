<?php

namespace App\Http\Resources;

use App\Models\MediaAsset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One media entry: {url, mime, width?, height?, duration_ms?, size_bytes}.
 * The URL is supplied by MediaUrls (Task 9) via additional(['url' => ...]).
 *
 * @mixin MediaAsset
 */
class MediaAssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return array_filter([
            'url' => $this->additional['url'] ?? null,
            'mime' => $this->mime,
            'width' => $this->width,
            'height' => $this->height,
            'duration_ms' => $this->duration_ms,
            'size_bytes' => (int) $this->size_bytes,
        ], fn ($v, $k) => $v !== null || in_array($k, ['url', 'mime', 'size_bytes'], true), ARRAY_FILTER_USE_BOTH);
    }
}
