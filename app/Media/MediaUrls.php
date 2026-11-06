<?php

namespace App\Media;

use App\Enums\Rating;
use App\Models\MediaAsset;
use App\Models\Submission;
use App\Models\User;
use App\Storage\ObjectStorage;

/**
 * URL for one of a submission's assets, or null when none may be handed out.
 * Callers have already checked visibility (and hide everything for hidden/removed items).
 *
 * Adult (decided by the CURRENT rating, not where the object sits):
 *  - only for a viewer whose effective max rating is adult, or the owner once 18+-confirmed;
 *  - only once the object is under private/. Until MoveSubmissionMedia has moved it, a signed
 *    URL to the public/ key would turn into a permanent anonymous URL by dropping the query,
 *    so the media is briefly missing instead.
 */
final class MediaUrls
{
    public static function for(MediaAsset $asset, Submission $submission, ?User $viewer): ?string
    {
        $storage = app(ObjectStorage::class);

        if (! MediaPaths::isPrivate($submission->rating)) {
            return $storage->publicUrl($asset->path);
        }
        if (! self::maySeeAdult($submission, $viewer) || ! str_starts_with($asset->path, MediaPaths::prefixFor($submission))) {
            return null;
        }

        return $storage->temporaryUrl($asset->path, (int) config('openpaws.signed_media_ttl'));
    }

    private static function maySeeAdult(Submission $submission, ?User $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }
        if ($viewer->effectiveMaxRating() === Rating::Adult) {
            return true;
        }

        return $viewer->getKey() === $submission->user_id && $viewer->adult_confirmed_at !== null;
    }
}
