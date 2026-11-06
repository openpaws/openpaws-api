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

        $tags = Tag::query()
            ->where('usage_count', '>', 0)
            ->where('slug', 'like', addcslashes($q, '\\%_').'%')
            ->orderByDesc('usage_count')->orderBy('slug')
            ->limit(10)->get();

        return TagResource::collection($tags);
    }

    public function show(Request $request, string $slug)
    {
        $resolved = TagNormalizer::normalize([$slug])[0] ?? null;
        $tag = $resolved === null ? null : Tag::where('slug', $resolved)->first();
        if ($tag === null) {
            throw new ModelNotFoundException;
        }

        $perPage = max(1, min((int) $request->query('per_page', config('openpaws.pagination.default')), config('openpaws.pagination.max')));

        $page = Submission::query()
            ->listedFor($request->user('sanctum'))
            ->whereIn('submissions.id', DB::table('submission_tag')->where('tag_id', $tag->id)->select('submission_id'))
            ->with(['user', 'tags', 'assets'])
            ->orderByDesc('submissions.published_at')->orderByDesc('submissions.id')
            ->cursorPaginate($perPage);

        return response()->json([
            'tag' => (new TagResource($tag))->resolve(),
            'data' => SubmissionResource::collection($page->getCollection())->resolve(),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode()],
        ]);
    }
}
