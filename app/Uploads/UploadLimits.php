<?php

namespace App\Uploads;

class UploadLimits
{
    public static function isMultipart(int $size): bool
    {
        return $size > (int) config('openpaws.multipart.threshold');
    }

    public static function partSize(): int
    {
        return (int) config('openpaws.multipart.part_size');
    }

    public static function partCount(int $size): int
    {
        return (int) ceil($size / self::partSize());
    }

    public static function sanitiseFilename(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? '';
        $name = substr($name, 0, 100);

        return $name === '' ? 'file' : $name;
    }
}
