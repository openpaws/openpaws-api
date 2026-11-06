<?php

namespace App\Enums;

enum SubmissionType: string
{
    case Image = 'image';
    case Writing = 'writing';
    case Audio = 'audio';
    case Video = 'video';

    /** The upload kind the main upload session must have for this type. */
    public function uploadKind(): UploadKind
    {
        return match ($this) {
            self::Image => UploadKind::Image,
            self::Audio => UploadKind::Audio,
            self::Video => UploadKind::Video,
            self::Writing => UploadKind::Document,
        };
    }

    /** Writing may be text-only; every other type needs an upload. */
    public function requiresUpload(): bool
    {
        return $this !== self::Writing;
    }

    public function allowsCover(): bool
    {
        return $this !== self::Image;
    }
}
