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
        // The count the viewer may see, never the all-ratings total.
        return ['slug' => $this->slug, 'usage_count' => $this->visibleUsage($request->user('sanctum'))];
    }
}
