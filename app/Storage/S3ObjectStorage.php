<?php

namespace App\Storage;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;

class S3ObjectStorage implements ObjectStorage
{
    private function client(): S3Client
    {
        return Storage::disk('s3')->getClient();
    }

    private function bucket(): string
    {
        return (string) config('filesystems.disks.s3.bucket');
    }

    private function presign(string $command, array $args, int $ttl): string
    {
        $cmd = $this->client()->getCommand($command, ['Bucket' => $this->bucket()] + $args);

        return (string) $this->client()->createPresignedRequest($cmd, '+'.$ttl.' seconds')->getUri();
    }

    public function presignPut(string $path, string $mime, int $ttl): string
    {
        return $this->presign('PutObject', ['Key' => $path, 'ContentType' => $mime], $ttl);
    }

    public function createMultipart(string $path, string $mime): string
    {
        $r = $this->client()->createMultipartUpload([
            'Bucket' => $this->bucket(), 'Key' => $path, 'ContentType' => $mime,
        ]);

        return (string) $r['UploadId'];
    }

    public function presignPart(string $path, string $uploadId, int $partNumber, int $ttl): string
    {
        return $this->presign('UploadPart', [
            'Key' => $path, 'UploadId' => $uploadId, 'PartNumber' => $partNumber,
        ], $ttl);
    }

    public function completeMultipart(string $path, string $uploadId, array $parts): void
    {
        $this->client()->completeMultipartUpload([
            'Bucket' => $this->bucket(), 'Key' => $path, 'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => array_map(
                fn (array $p) => ['PartNumber' => (int) $p['part_number'], 'ETag' => $p['etag']],
                $parts,
            )],
        ]);
    }

    public function abortMultipart(string $path, string $uploadId): void
    {
        $this->client()->abortMultipartUpload([
            'Bucket' => $this->bucket(), 'Key' => $path, 'UploadId' => $uploadId,
        ]);
    }

    public function exists(string $path): bool
    {
        return $this->client()->doesObjectExistV2($this->bucket(), $path);
    }

    public function size(string $path): ?int
    {
        if (! $this->exists($path)) {
            return null;
        }
        $r = $this->client()->headObject(['Bucket' => $this->bucket(), 'Key' => $path]);

        return (int) $r['ContentLength'];
    }

    public function downloadToTemp(string $path): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'opaws');
        $this->client()->getObject([
            'Bucket' => $this->bucket(), 'Key' => $path, 'SaveAs' => $tmp,
        ]);

        return $tmp;
    }

    public function putFile(string $path, string $localPath, string $mime): void
    {
        $this->client()->putObject([
            'Bucket' => $this->bucket(), 'Key' => $path,
            'SourceFile' => $localPath, 'ContentType' => $mime,
        ]);
    }

    public function delete(string $path): void
    {
        $this->client()->deleteObject(['Bucket' => $this->bucket(), 'Key' => $path]);
    }

    public function deletePrefix(string $prefix): void
    {
        $this->client()->deleteMatchingObjects($this->bucket(), $prefix);
    }

    public function publicUrl(string $path): string
    {
        return rtrim((string) config('openpaws.cdn_url'), '/').'/'.$path;
    }

    public function temporaryUrl(string $path, int $ttl): string
    {
        return $this->presign('GetObject', ['Key' => $path], $ttl);
    }
}
