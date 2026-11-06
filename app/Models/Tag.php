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
     * @param  array<int>  $tagIds
     */
    public static function recount(array $tagIds): void
    {
        $tagIds = array_values(array_unique(array_filter($tagIds)));
        if ($tagIds === []) {
            return;
        }

        $counted = DB::table('submission_tag')
            ->join('submissions', 'submissions.id', '=', 'submission_tag.submission_id')
            ->whereColumn('submission_tag.tag_id', 'tags.id')
            ->whereNull('submissions.deleted_at')
            ->where('submissions.status', SubmissionStatus::Published->value)
            ->whereNotNull('submissions.published_at')
            ->where('submissions.visibility', Visibility::Public->value)
            ->selectRaw('count(*)');

        static::query()->whereIn('id', $tagIds)->update(['usage_count' => $counted]);
    }
}
