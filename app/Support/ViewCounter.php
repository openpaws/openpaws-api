<?php

namespace App\Support;

use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class ViewCounter
{
    private const TTL = 86400;

    /** Counts at most one view per viewer per day; owners and unpublished submissions are never counted. */
    public static function record(Submission $s, ?User $viewer, string $ip, string $ua): void
    {
        if (! $s->isPublished()) {
            return;
        }
        if ($viewer !== null && $viewer->id === $s->user_id) {
            return;
        }

        $viewerKey = $viewer !== null ? 'u'.$viewer->id : 'g'.sha1($ip.'|'.$ua);
        $key = 'views:'.$s->id.':'.$viewerKey.':'.now()->format('Y-m-d');

        // Cache::add is an atomic SET NX on redis.
        if (! Cache::add($key, 1, self::TTL)) {
            return;
        }

        // Base query: does not touch updated_at.
        Submission::withTrashed()->whereKey($s->id)->toBase()->increment('views_count');
        $s->views_count++;
        $s->syncOriginalAttribute('views_count');
    }
}
