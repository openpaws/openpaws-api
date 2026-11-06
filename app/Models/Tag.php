<?php

namespace App\Models;

use App\Enums\SubmissionStatus;
use App\Enums\Visibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Tag extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['usage_count' => 'integer'];
    }

    public function submissions(): BelongsToMany
    {
        return $this->belongsToMany(Submission::class, 'submission_tag');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(TagAlias::class);
    }

    /**
     * Recomputes usage_count: published + public + non-trashed submissions only.
     *
     * Runs only after the surrounding transaction (if any) commits, and outside it: the counts come
     * from one plain (non-locking, consistent-read) SELECT ... GROUP BY, then each tag is updated by
     * id with literal values in ascending id order. That takes no shared locks on submissions or
     * submission_tag, so it cannot form a lock cycle with a request editing a submission's tags.
     * Under concurrency a count may be briefly stale; the next recount of that tag corrects it.
     *
     * @param  array<int>  $tagIds
     */
    public static function recount(array $tagIds): void
    {
        $tagIds = array_values(array_unique(array_map('intval', array_filter($tagIds))));
        if ($tagIds === []) {
            return;
        }
        sort($tagIds, SORT_NUMERIC);

        DB::afterCommit(fn () => static::recountNow($tagIds));
    }

    /** @param  list<int>  $tagIds  sorted ascending */
    private static function recountNow(array $tagIds): void
    {
        $counts = DB::table('submission_tag')
            ->join('submissions', 'submissions.id', '=', 'submission_tag.submission_id')
            ->whereIn('submission_tag.tag_id', $tagIds)
            ->whereNull('submissions.deleted_at')
            ->where('submissions.status', SubmissionStatus::Published->value)
            ->whereNotNull('submissions.published_at')
            ->where('submissions.visibility', Visibility::Public->value)
            ->groupBy('submission_tag.tag_id')
            ->selectRaw('submission_tag.tag_id as tag_id, count(*) as aggregate')
            ->pluck('aggregate', 'tag_id');

        foreach ($tagIds as $id) {
            static::query()->whereKey($id)->update(['usage_count' => (int) ($counts[$id] ?? 0)]);
        }
    }
}
