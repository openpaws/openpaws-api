<?php

namespace App\Models;

use App\Enums\AssetRole;
use App\Enums\Rating;
use App\Enums\SubmissionStatus;
use App\Enums\SubmissionType;
use App\Enums\Visibility;
use App\Models\Concerns\HasPublicId;
use Closure;
use Database\Factories\SubmissionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\Searchable;
use Throwable;

class Submission extends Model
{
    /** @use HasFactory<SubmissionFactory> */
    use HasFactory, HasPublicId, SoftDeletes;

    use Searchable {
        queueMakeSearchable as scoutQueueMakeSearchable;
        queueRemoveFromSearch as scoutQueueRemoveFromSearch;
    }

    protected $guarded = ['id', 'public_id', 'views_count'];

    protected $attributes = ['views_count' => 0];

    protected function casts(): array
    {
        return [
            'type' => SubmissionType::class,
            'rating' => Rating::class,
            'status' => SubmissionStatus::class,
            'visibility' => Visibility::class,
            'published_at' => 'datetime',
            'views_count' => 'integer',
        ];
    }

    /** @return array<string, mixed> */
    public function toSearchableArray(): array
    {
        $this->loadMissing(['tags', 'user']);

        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'title' => $this->title,
            'description' => $this->description,
            'tags' => $this->tags->pluck('slug')->values()->all(),
            'type' => $this->type->value,
            'rating' => $this->rating->value,
            'user_id' => $this->user_id,
            'username' => $this->user?->username,
            'published_at' => $this->published_at?->getTimestamp(),
            'views_count' => $this->views_count,
        ];
    }

    /**
     * Search is not critical: a failure to index (or to queue the indexing) is logged and never
     * fails the caller, so a Meilisearch outage cannot fail processing or 500 a committed write.
     * The next save or `scout:import` brings the index back in line.
     */
    public function queueMakeSearchable($models)
    {
        $this->guardSearchSync('make searchable', $models, fn () => $this->scoutQueueMakeSearchable($models));
    }

    public function queueRemoveFromSearch($models)
    {
        $this->guardSearchSync('remove from search', $models, fn () => $this->scoutQueueRemoveFromSearch($models));
    }

    private function guardSearchSync(string $action, $models, Closure $sync): void
    {
        try {
            $sync();
        } catch (Throwable $e) {
            Log::warning('Could not update the search index', [
                'action' => $action,
                'public_ids' => collect($models)->pluck('public_id')->all(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public function shouldBeSearchable(): bool
    {
        return $this->status === SubmissionStatus::Published
            && $this->published_at !== null
            && $this->visibility === Visibility::Public
            && ! $this->trashed();
    }

    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->with(['tags', 'user']);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'submission_tag')->orderBy('tags.slug');
    }

    public function folders(): BelongsToMany
    {
        return $this->belongsToMany(Folder::class)->withPivot('position')->orderBy('folders.position');
    }

    public function assets(): MorphMany
    {
        return $this->morphMany(MediaAsset::class, 'owner');
    }

    public function asset(AssetRole $role): ?MediaAsset
    {
        if ($this->relationLoaded('assets')) {
            return $this->assets->first(fn (MediaAsset $a) => $a->role === $role);
        }

        return $this->assets()->where('role', $role->value)->first();
    }

    public function isPublished(): bool
    {
        return $this->status === SubmissionStatus::Published && $this->published_at !== null;
    }

    /**
     * Single-lookup scope. The owner sees all of their own (non-trashed) submissions;
     * everyone else only published ones within their effective max rating.
     * Unlisted submissions are included (they are viewable by link).
     */
    public function scopeVisibleTo(Builder $query, ?User $viewer): Builder
    {
        return $query->where(function (Builder $q) use ($viewer) {
            $q->where(fn (Builder $pub) => $this->applyPublishedFor($pub, $viewer));
            if ($viewer !== null) {
                $q->orWhere($q->qualifyColumn('user_id'), $viewer->getKey());
            }
        });
    }

    /**
     * Listing scope: no owner exception, public visibility only.
     * All listings must use this; all single lookups use visibleTo.
     */
    public function scopeListedFor(Builder $query, ?User $viewer): Builder
    {
        return $query->where(function (Builder $q) use ($viewer) {
            $this->applyPublishedFor($q, $viewer);
            $q->where($q->qualifyColumn('visibility'), Visibility::Public->value);
        });
    }

    private function applyPublishedFor(Builder $q, ?User $viewer): void
    {
        $max = $viewer?->effectiveMaxRating() ?? Rating::General;

        $q->where($q->qualifyColumn('status'), SubmissionStatus::Published->value)
            ->whereNotNull($q->qualifyColumn('published_at'))
            ->whereIn($q->qualifyColumn('rating'), array_map(fn (Rating $r) => $r->value, Rating::upTo($max)));
    }
}
