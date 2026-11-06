<?php

namespace App\Http\Resources;

use App\Models\UploadSession;
use App\Uploads\UploadLimits;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UploadSession */
class UploadSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $out = [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'multipart' => $this->multipart,
            'expires_at' => $this->expires_at->toIso8601String(),
        ];
        if ($this->multipart) {
            $out['part_size'] = UploadLimits::partSize();
            $out['part_count'] = $this->part_count;
        } elseif (isset($this->additional['url'])) {
            $out['url'] = $this->additional['url'];
        }

        return $out;
    }
}
