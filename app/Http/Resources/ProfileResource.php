<?php

namespace App\Http\Resources;

use App\Models\Profile;
use App\Support\Markdown;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Profile */
class ProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'bio' => $this->bio,
            'bio_html' => Markdown::toSafeHtml($this->bio),
            'links' => array_values($this->links ?? []),
            'species' => $this->species,
            'pronouns' => $this->pronouns,
            'pinned' => [], // Task 6 fills this in via listedFor
        ];
    }
}
