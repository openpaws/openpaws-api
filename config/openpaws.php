<?php

$mb = 1024 * 1024;

return [
    // Maximum upload sizes in bytes.
    'limits' => [
        'image' => (int) env('OPENPAWS_LIMIT_IMAGE', 50 * $mb),
        'audio' => (int) env('OPENPAWS_LIMIT_AUDIO', 200 * $mb),
        'video' => (int) env('OPENPAWS_LIMIT_VIDEO', 2048 * $mb),
        'document' => (int) env('OPENPAWS_LIMIT_DOCUMENT', 10 * $mb),
        'cover' => (int) env('OPENPAWS_LIMIT_COVER', 10 * $mb),
        'avatar' => (int) env('OPENPAWS_LIMIT_AVATAR', 10 * $mb),
        'banner' => (int) env('OPENPAWS_LIMIT_BANNER', 10 * $mb),
    ],

    'multipart' => [
        'threshold' => (int) env('OPENPAWS_MULTIPART_THRESHOLD', 100 * $mb),
        'part_size' => (int) env('OPENPAWS_MULTIPART_PART_SIZE', 50 * $mb),
    ],

    // Seconds.
    'presign_ttl' => (int) env('OPENPAWS_PRESIGN_TTL', 3600),
    'signed_media_ttl' => (int) env('OPENPAWS_SIGNED_MEDIA_TTL', 600),

    'quota' => [
        'daily_count' => (int) env('OPENPAWS_QUOTA_DAILY_COUNT', 100),
        'daily_bytes' => (int) env('OPENPAWS_QUOTA_DAILY_BYTES', 10 * 1024 * $mb),
    ],

    'uploads' => [
        'stale_hours' => (int) env('OPENPAWS_UPLOADS_STALE_HOURS', 24),
    ],

    'purge_days' => (int) env('OPENPAWS_PURGE_DAYS', 30),

    'ui_url' => env('OPENPAWS_UI_URL', 'http://localhost:3000'),
    'cdn_url' => env('OPENPAWS_CDN_URL'),

    'pagination' => [
        'default' => (int) env('OPENPAWS_PAGINATION_DEFAULT', 48),
        'max' => (int) env('OPENPAWS_PAGINATION_MAX', 96),
    ],
];
