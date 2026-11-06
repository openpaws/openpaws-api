<?php

namespace App\Storage;

use Illuminate\Support\Facades\File;

/**
 * Local-filesystem implementation for tests. Objects live under
 * storage/framework/testing/objects. completeMultipart simply accepts the
 * object already placed at $path via simulateUpload() (parts are not assembled).
 */
class FakeObjectStorage implements ObjectStorage
{
    /** @var array<string, string> uploadId => path */
    public array $multiparts = [];

    public function __construct(private ?string $root = null)
    {
        $this->root ??= storage_path('framework/testing/objects');
        File::ensureDirectoryExists($this->root);
    }

    public function flush(): void
    {
        File::deleteDirectory($this->root);
        File::ensureDirectoryExists($this->root);
        $this->multiparts = [];
    }

    private function full(string $path): string
    {
        return $this->root.'/'.ltrim($path, '/');
    }

    /** Test helper: place a file as though the browser had uploaded it. */
    public function simulateUpload(string $path, string $localFixture): void
    {
        File::ensureDirectoryExists(dirname($this->full($path)));
        File::copy($localFixture, $this->full($path));
    }

    public function presignPut(string $path, string $mime, int $ttl): string
    {
        return 'fake://put/'.$path;
    }

    public function createMultipart(string $path, string $mime): string
    {
        $id = 'fake-upload-'.bin2hex(random_bytes(8));
        $this->multiparts[$id] = $path;

        return $id;
    }

    public function presignPart(string $path, string $uploadId, int $partNumber, int $ttl): string
    {
        return 'fake://part/'.$path.'?uploadId='.$uploadId.'&partNumber='.$partNumber;
    }

    public function completeMultipart(string $path, string $uploadId, array $parts): void
    {
        unset($this->multiparts[$uploadId]);
    }

    public function abortMultipart(string $path, string $uploadId): void
    {
        unset($this->multiparts[$uploadId]);
    }

    public function exists(string $path): bool
    {
        return is_file($this->full($path));
    }

    public function size(string $path): ?int
    {
        return $this->exists($path) ? filesize($this->full($path)) : null;
    }

    public function downloadToTemp(string $path): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'opaws');
        File::copy($this->full($path), $tmp);

        return $tmp;
    }

    public function putFile(string $path, string $localPath, string $mime): void
    {
        $this->simulateUpload($path, $localPath);
    }

    public function delete(string $path): void
    {
        File::delete($this->full($path));
    }

    public function deletePrefix(string $prefix): void
    {
        $full = $this->full($prefix);
        if (is_dir($full)) {
            File::deleteDirectory($full);

            return;
        }
        foreach (glob($full.'*') ?: [] as $f) {
            is_dir($f) ? File::deleteDirectory($f) : File::delete($f);
        }
    }

    public function move(string $from, string $to): void
    {
        File::ensureDirectoryExists(dirname($this->full($to)));
        if (! File::move($this->full($from), $this->full($to))) {
            throw new \RuntimeException("Could not move [{$from}] to [{$to}].");
        }
    }

    public function publicUrl(string $path): string
    {
        return rtrim((string) config('openpaws.cdn_url'), '/').'/'.$path;
    }

    public function temporaryUrl(string $path, int $ttl): string
    {
        return 'fake://temp/'.$path.'?expires='.(now()->getTimestamp() + $ttl);
    }
}
