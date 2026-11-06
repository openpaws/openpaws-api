<?php

namespace App\Http\Resources;

use App\Enums\SubmissionType;
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
            'tags' => [], // Task 8
            'views_count' => (int) $this->views_count,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'user' => new UserResource($this->user),
            'folders' => [], // Task 10
            'media' => new \stdClass, // Task 9 (MediaUrls)
        ];
    }
}
