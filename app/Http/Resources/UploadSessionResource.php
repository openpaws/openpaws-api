<?php

namespace App\Http\Resources;

use App\Models\UploadSession;
use App\Uploads\UploadLimits;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UploadSession */
class UploadSessionResource extends JsonResource
{
    /** Presigned PUT URL for a single-PUT upload; returned as data.url only. */
    private ?string $putUrl = null;

    public function withPutUrl(string $url): static
    {
        $this->putUrl = $url;

        return $this;
    }

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
        } elseif ($this->putUrl !== null) {
            $out['url'] = $this->putUrl;
        }

        return $out;
    }
}
