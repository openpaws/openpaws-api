<?php

namespace App\Enums;

enum UploadKind: string
{
    case Image = 'image';
    case Audio = 'audio';
    case Video = 'video';
    case Document = 'document';
    case Cover = 'cover';
    case Avatar = 'avatar';
    case Banner = 'banner';

    public function maxBytes(): int
    {
        return (int) config('openpaws.limits.'.$this->value);
    }

    /** @return list<string> */
    public function allowedMimes(): array
    {
        return match ($this) {
            self::Image, self::Cover, self::Avatar, self::Banner => ['image/png', 'image/jpeg', 'image/gif', 'image/webp'],
            self::Audio => ['audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/flac', 'audio/x-flac', 'audio/ogg', 'audio/mp4', 'audio/x-m4a'],
            self::Video => ['video/mp4', 'video/webm', 'video/quicktime'],
            self::Document => [
                'text/plain',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.oasis.opendocument.text',
                'application/pdf',
            ],
        };
    }
}
