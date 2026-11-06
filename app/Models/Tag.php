<?php

namespace App\Models;

use App\Enums\Rating;
use App\Enums\SubmissionStatus;
use App\Enums\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Tag extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'usage_count' => 'integer',
            'usage_general' => 'integer',
            'usage_mature' => 'integer',
            'usage_adult' => 'integer',
        ];
    }

    public function submissions(): BelongsToMany
    {
        return $this->belongsToMany(Submission::class, 'submission_tag');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(TagAlias::class);
    }

    /** The per-rating count column for a rating (fixed names, safe to put in SQL). */
    public static function usageColumn(Rating $rating): string
    {
        return 'usage_'.$rating->value;
    }

    /** SQL expression for the count the viewer may see: the sum over the ratings they may view. */
    public static function visibleUsageSql(?User $viewer): string
    {
        $max = $viewer?->effectiveMaxRating() ?? Rating::General;

        return '('.implode(' + ', array_map(
            fn (Rating $r) => 'tags.'.self::usageColumn($r),
            Rating::upTo($max),
        )).')';
    }

    /** Only tags the viewer can see at least one listed submission for. */
    public function scopeVisibleTo(Builder $query, ?User $viewer): Builder
    {
        return $query->whereRaw(self::visibleUsageSql($viewer).' > 0');
    }

    /** Number of listed submissions with this tag that the viewer may see. */
    public function visibleUsage(?User $viewer): int
    {
        $max = $viewer?->effectiveMaxRating() ?? Rating::General;

        return array_sum(array_map(
            fn (Rating $r) => (int) $this->getAttribute(self::usageColumn($r)),
            Rating::upTo($max),
        ));
    }

    /**
     * Recomputes the per-rating counts (and usage_count, their sum): published + public +
     * non-trashed submissions only.
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
        $rows = DB::table('submission_tag')
            ->join('submissions', 'submissions.id', '=', 'submission_tag.submission_id')
            ->whereIn('submission_tag.tag_id', $tagIds)
            ->whereNull('submissions.deleted_at')
            ->where('submissions.status', SubmissionStatus::Published->value)
            ->whereNotNull('submissions.published_at')
            ->where('submissions.visibility', Visibility::Public->value)
            ->groupBy('submission_tag.tag_id', 'submissions.rating')
            ->selectRaw('submission_tag.tag_id as tag_id, submissions.rating as rating, count(*) as aggregate')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row->tag_id][$row->rating] = (int) $row->aggregate;
        }

        foreach ($tagIds as $id) {
            $values = [];
            foreach (Rating::cases() as $rating) {
                $values[self::usageColumn($rating)] = $counts[$id][$rating->value] ?? 0;
            }
            $values['usage_count'] = array_sum($values);
            static::query()->whereKey($id)->update($values);
        }
    }
}
