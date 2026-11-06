<?php

namespace App\Http\Resources;

use App\Models\Profile;
use App\Models\Submission;
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
            'pinned' => SubmissionResource::collection($this->pinnedFor($request))->resolve($request),
        ];
    }

    /** Pinned submissions the viewer may see in a listing, in the owner's stored order. */
    private function pinnedFor(Request $request): array
    {
        $ids = array_values(array_map('strval', $this->pinned_submission_ids ?? []));
        if ($ids === [] || $this->user_id === null) {
            return [];
        }

        $found = Submission::listedFor($request->user('sanctum'))
            ->where('user_id', $this->user_id)
            ->whereIn('public_id', $ids)
            ->with('user')
            ->get()
            ->keyBy('public_id');

        return array_values(array_filter(array_map(fn (string $id) => $found->get($id), $ids)));
    }
}
