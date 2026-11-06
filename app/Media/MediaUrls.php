<?php

namespace App\Media;

use App\Models\MediaAsset;
use App\Models\Submission;
use App\Storage\ObjectStorage;

/**
 * URL for one of a submission's assets. Callers have already checked visibility.
 * The decision uses the submission's CURRENT rating, not where the object sits, so an
 * item that just became adult is signed even before MoveSubmissionMedia has run.
 */
final class MediaUrls
{
    public static function for(MediaAsset $asset, Submission $submission): string
    {
        $storage = app(ObjectStorage::class);

        return MediaPaths::isPrivate($submission->rating)
            ? $storage->temporaryUrl($asset->path, (int) config('openpaws.signed_media_ttl'))
            : $storage->publicUrl($asset->path);
    }
}
