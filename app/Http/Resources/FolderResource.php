<?php

namespace App\Http\Resources;

use App\Models\Folder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `count` is the number of submissions listed for the viewer; set it as the `listed_count`
 * attribute (see FolderCounts), otherwise it is 0.
 *
 * @mixin Folder
 */
class FolderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'description' => $this->description,
            'kind' => $this->kind->value,
            'position' => (int) $this->position,
            'count' => (int) ($this->listed_count ?? 0),
        ];
    }
}
