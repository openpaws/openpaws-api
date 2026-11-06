<?php

namespace App\Media;

use App\Enums\Rating;
use App\Models\Submission;

/**
 * Where a submission's assets live in the bucket. General/mature assets sit under
 * public/ (anonymously readable, served via the CDN); adult ones under private/
 * (reachable only through short-lived signed URLs). Staging uploads stay in staging/.
 */
final class MediaPaths
{
    public static function isPrivate(Rating $rating): bool
    {
        return $rating === Rating::Adult;
    }

    /** Prefix for the submission's current rating, e.g. "public/media/{public_id}/". */
    public static function prefixFor(Submission $submission): string
    {
        return self::prefix($submission->public_id, self::isPrivate($submission->rating));
    }

    /** @return list<string> Both possible prefixes (for deleting everything a submission stored). */
    public static function allPrefixes(Submission $submission): array
    {
        return [self::prefix($submission->public_id, false), self::prefix($submission->public_id, true)];
    }

    private static function prefix(string $publicId, bool $private): string
    {
        return ($private ? 'private' : 'public')."/media/{$publicId}/";
    }
}
