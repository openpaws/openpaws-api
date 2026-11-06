<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Rating;
use App\Enums\SubmissionType;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubmissionResource;
use App\Models\Submission;
use App\Support\TagNormalizer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SearchController extends Controller
{
    private const MAX_TAGS = 20;

    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'tags' => ['nullable', 'string'],
            'exclude_tags' => ['nullable', 'string'],
            'type' => ['nullable', Rule::in(array_column(SubmissionType::cases(), 'value'))],
            'sort' => ['nullable', Rule::in(['relevance', 'new', 'popular'])],
            'per_page' => ['nullable', 'integer'],
            'cursor' => ['nullable', 'string'],
        ]);

        $q = trim((string) ($data['q'] ?? ''));
        $required = $this->tags($data['tags'] ?? null, 'tags');
        $excluded = $this->tags($data['exclude_tags'] ?? null, 'exclude_tags');
        $sort = $data['sort'] ?? ($q === '' ? 'new' : 'relevance');
        $perPage = max(1, min((int) ($data['per_page'] ?? config('openpaws.pagination.default')), config('openpaws.pagination.max')));
        $page = $this->page($data['cursor'] ?? null);

        $viewer = $request->user('sanctum');
        $max = $viewer?->effectiveMaxRating() ?? Rating::General;

        $search = Submission::search($q)
            ->whereIn('rating', array_map(fn (Rating $r) => $r->value, Rating::upTo($max)));
        if (! empty($data['type'])) {
            $search->where('type', $data['type']);
        }
        foreach ($required as $tag) {
            $search->where('tags', $tag);
        }
        if ($excluded !== []) {
            $search->whereNotIn('tags', $excluded);
        }
        match ($sort) {
            'new' => $search->orderBy('published_at', 'desc'),
            'popular' => $search->orderBy('views_count', 'desc'),
            default => null,
        };

        // Second guard: the models are re-loaded through listedFor (order is kept by Scout).
        $results = $search
            ->query(fn ($query) => $query->listedFor($viewer)->with(['user', 'tags', 'assets', 'folders']))
            ->paginate($perPage, 'page', $page);

        $next = $results->hasMorePages()
            ? rtrim(strtr(base64_encode(json_encode(['p' => $page + 1])), '+/', '-_'), '=')
            : null;

        return response()->json([
            'data' => SubmissionResource::collection($results->getCollection())->resolve(),
            'meta' => ['next_cursor' => $next],
        ]);
    }

    /** @return list<string> */
    private function tags(?string $raw, string $field): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $tags = TagNormalizer::normalize(explode(',', $raw));
        if (count($tags) > self::MAX_TAGS) {
            throw ValidationException::withMessages([$field => ['At most '.self::MAX_TAGS.' tags are allowed.']]);
        }

        return $tags;
    }

    private function page(?string $cursor): int
    {
        if ($cursor === null || $cursor === '') {
            return 1;
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        $json = $decoded === false ? null : json_decode($decoded, true);
        $p = is_array($json) ? ($json['p'] ?? null) : null;
        if (! is_int($p) || $p < 1 || $p > 100000) {
            throw ValidationException::withMessages(['cursor' => ['The cursor is invalid.']]);
        }

        return $p;
    }
}
