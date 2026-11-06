<?php

namespace App\Http\Resources;

use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tag */
class TagResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return ['slug' => $this->slug, 'usage_count' => (int) $this->usage_count];
    }
}
