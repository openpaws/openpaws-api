<?php

namespace App\Http\Resources;

use App\Enums\AssetRole;
use App\Enums\SubmissionStatus;
use App\Enums\SubmissionType;
use App\Media\MediaUrls;
use App\Models\MediaAsset;
use App\Models\Submission;
use App\Support\Markdown;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Submission */
class SubmissionResource extends JsonResource
{
    private bool $includeBody = false;

    /** Include body/body_html (writing only). Used by the show endpoint only. */
    public function withBody(): static
    {
        $this->includeBody = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->public_id,
            'type' => $this->type->value,
            'title' => $this->title,
            'description' => $this->description,
            'description_html' => Markdown::toSafeHtml($this->description),
        ];

        if ($this->includeBody && $this->type === SubmissionType::Writing) {
            $data['body'] = $this->body;
            $data['body_html'] = Markdown::toSafeHtml($this->body);
        }

        return $data + [
            'rating' => $this->rating->value,
            'status' => $this->status->value,
            'visibility' => $this->visibility->value,
            'tags' => $this->tags->pluck('slug')->values()->all(),
            'views_count' => (int) $this->views_count,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'user' => new UserResource($this->user),
            'folders' => $this->folders->map(fn ($f) => ['id' => $f->public_id, 'name' => $f->name, 'kind' => $f->kind->value])->values()->all(),
            'media' => $this->media($request),
        ];
    }

    /** Roles exposed in `media`, in output order. */
    private const MEDIA_ROLES = [
        AssetRole::ThumbSm, AssetRole::ThumbMd, AssetRole::ThumbLg, AssetRole::Preview, AssetRole::Original,
        AssetRole::Transcode, AssetRole::Cover, AssetRole::Waveform, AssetRole::Document,
    ];

    /**
     * Existing assets keyed by role. Audio and video never expose the raw upload (`original`);
     * they expose `transcode`. Hidden or removed submissions get no URLs at all (owner included),
     * and roles MediaUrls gives no URL for are left out. Always a JSON object, even when empty.
     */
    private function media(Request $request): object
    {
        if (in_array($this->status, [SubmissionStatus::Hidden, SubmissionStatus::Removed], true)) {
            return new \stdClass;
        }
        $viewer = $request->user('sanctum');
        $hideOriginal = in_array($this->type, [SubmissionType::Audio, SubmissionType::Video], true);
        $byRole = $this->assets->keyBy(fn (MediaAsset $a) => $a->role->value);

        $media = [];
        foreach (self::MEDIA_ROLES as $role) {
            $asset = $byRole->get($role->value);
            if ($asset === null || ($role === AssetRole::Original && $hideOriginal)) {
                continue;
            }
            $url = MediaUrls::for($asset, $this->resource, $viewer);
            if ($url !== null) {
                $media[$role->value] = (new MediaAssetResource($asset, $url))->resolve($request);
            }
        }

        return (object) $media;
    }
}
