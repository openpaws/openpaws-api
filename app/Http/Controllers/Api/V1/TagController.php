<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubmissionResource;
use App\Http\Resources\TagResource;
use App\Models\Submission;
use App\Models\Tag;
use App\Support\TagNormalizer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TagController extends Controller
{
    public function autocomplete(Request $request)
    {
        $q = TagNormalizer::slug((string) $request->query('q', ''));
        if ($q === '') {
            return response()->json(['data' => []]);
        }

        // Names and counts come only from submissions the viewer may see (R29).
        $viewer = $request->user('sanctum');
        $tags = Tag::query()
            ->visibleTo($viewer)
            ->where('slug', 'like', addcslashes($q, '\\%_').'%')
            ->orderByRaw(Tag::visibleUsageSql($viewer).' desc')->orderBy('slug')
            ->limit(10)->get();

        return TagResource::collection($tags);
    }

    public function show(Request $request, string $slug)
    {
        $resolved = TagNormalizer::normalize([$slug])[0] ?? null;
        $viewer = $request->user('sanctum');
        // A tag used only on work above the viewer's rating does not exist for them (R29).
        $tag = $resolved === null ? null : Tag::where('slug', $resolved)->visibleTo($viewer)->first();
        if ($tag === null) {
            throw new ModelNotFoundException;
        }

        $perPage = max(1, min((int) $request->query('per_page', config('openpaws.pagination.default')), config('openpaws.pagination.max')));

        $page = Submission::query()
            ->listedFor($viewer)
            ->whereIn('submissions.id', DB::table('submission_tag')->where('tag_id', $tag->id)->select('submission_id'))
            ->with(['user', 'tags', 'assets', 'folders'])
            ->orderByDesc('submissions.published_at')->orderByDesc('submissions.id')
            ->cursorPaginate($perPage);

        return response()->json([
            'tag' => (new TagResource($tag))->resolve(),
            'data' => SubmissionResource::collection($page->getCollection())->resolve(),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode()],
        ]);
    }
}
