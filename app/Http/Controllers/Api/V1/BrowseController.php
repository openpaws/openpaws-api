<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SubmissionType;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubmissionResource;
use App\Models\Submission;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BrowseController extends Controller
{
    private const PERIOD_DAYS = ['day' => 1, 'week' => 7, 'month' => 30];

    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'type' => ['nullable', Rule::in(array_column(SubmissionType::cases(), 'value'))],
            'sort' => ['nullable', Rule::in(['new', 'popular'])],
            'period' => ['nullable', Rule::in(['day', 'week', 'month', 'all'])],
            'per_page' => ['nullable', 'integer'],
        ]);
        $perPage = max(1, min((int) ($data['per_page'] ?? config('openpaws.pagination.default')), config('openpaws.pagination.max')));

        $query = Submission::query()
            ->listedFor($request->user('sanctum'))
            ->when($data['type'] ?? null, fn ($q, $type) => $q->where('submissions.type', $type))
            ->with(['user', 'tags', 'assets', 'folders']);

        if (($data['sort'] ?? 'new') === 'popular') {
            $days = self::PERIOD_DAYS[$data['period'] ?? 'week'] ?? null;
            $query->when($days, fn ($q) => $q->where('submissions.published_at', '>=', now()->subDays($days)))
                ->orderByDesc('submissions.views_count')->orderByDesc('submissions.id');
        } else {
            $query->orderByDesc('submissions.published_at')->orderByDesc('submissions.id');
        }

        $page = $query->cursorPaginate($perPage);

        return response()->json([
            'data' => SubmissionResource::collection($page->getCollection())->resolve(),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode()],
        ]);
    }
}
