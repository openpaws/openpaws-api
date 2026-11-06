<?php

namespace App\Storage;

interface ObjectStorage
{
    public function presignPut(string $path, string $mime, int $ttl): string;

    /** Returns the multipart uploadId. */
    public function createMultipart(string $path, string $mime): string;

    public function presignPart(string $path, string $uploadId, int $partNumber, int $ttl): string;

    /** @param list<array{part_number:int, etag:string}> $parts */
    public function completeMultipart(string $path, string $uploadId, array $parts): void;

    public function abortMultipart(string $path, string $uploadId): void;

    public function exists(string $path): bool;

    public function size(string $path): ?int;

    /** Local temp file path containing the object. */
    public function downloadToTemp(string $path): string;

    public function putFile(string $path, string $localPath, string $mime): void;

    public function delete(string $path): void;

    public function deletePrefix(string $prefix): void;

    public function publicUrl(string $path): string;

    public function temporaryUrl(string $path, int $ttl): string;
}
