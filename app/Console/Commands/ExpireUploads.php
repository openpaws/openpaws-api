<?php

namespace App\Console\Commands;

use App\Enums\UploadStatus;
use App\Models\UploadSession;
use App\Storage\ObjectStorage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('uploads:expire')]
#[Description('Expire abandoned upload sessions and delete their staged objects')]
class ExpireUploads extends Command
{
    /** Consumed sessions are only swept for leftover staging objects for this many days. */
    private const CONSUMED_SWEEP_DAYS = 7;

    public function handle(ObjectStorage $storage): int
    {
        $expired = 0;
        $swept = 0;
        $failures = 0;

        UploadSession::query()
            ->whereIn('status', [UploadStatus::Pending->value, UploadStatus::Uploaded->value])
            ->where('expires_at', '<', now())
            ->chunkById(100, function ($sessions) use ($storage, &$expired, &$failures) {
                foreach ($sessions as $stale) {
                    $target = $this->expire($stale->id);
                    if ($target === null) {
                        continue;
                    }
                    $expired++;
                    // Storage work only after the status change is committed.
                    $failures += $this->cleanStaging($storage, $target['path'], $target['upload_id']);
                }
            });

        // Staging objects of consumed sessions that a failed post-publish delete left behind.
        UploadSession::query()
            ->where('status', UploadStatus::Consumed->value)
            ->where('updated_at', '<', now()->subHours((int) config('openpaws.uploads.stale_hours')))
            ->where('updated_at', '>', now()->subDays(self::CONSUMED_SWEEP_DAYS))
            ->chunkById(100, function ($sessions) use ($storage, &$swept, &$failures) {
                foreach ($sessions as $session) {
                    try {
                        if ($storage->exists($session->path)) {
                            $storage->delete($session->path);
                            $swept++;
                        }
                    } catch (Throwable $e) {
                        $failures++;
                        Log::warning('Could not delete leftover staging object', ['session' => $session->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        $message = "uploads:expire expired {$expired} session(s), removed {$swept} leftover consumed staging object(s), {$failures} storage failure(s).";
        Log::info($message);
        $this->info($message);

        return self::SUCCESS;
    }

    /** @return array{path:string, upload_id:?string}|null null when the session was consumed or already handled meanwhile */
    private function expire(string $id): ?array
    {
        return DB::transaction(function () use ($id) {
            $session = UploadSession::whereKey($id)->lockForUpdate()->first();
            if ($session === null
                || ! in_array($session->status, [UploadStatus::Pending, UploadStatus::Uploaded], true)
                || ! $session->expires_at->isPast()) {
                return null;
            }
            $session->status = UploadStatus::Expired;
            $session->save();

            return ['path' => $session->path, 'upload_id' => $session->multipart_upload_id];
        });
    }

    /** Best effort; returns the number of failed operations. */
    private function cleanStaging(ObjectStorage $storage, string $path, ?string $uploadId): int
    {
        $failures = 0;
        if ($uploadId !== null) {
            try {
                $storage->abortMultipart($path, $uploadId);
            } catch (Throwable $e) {
                $failures++;
                Log::warning('Could not abort multipart upload', ['path' => $path, 'error' => $e->getMessage()]);
            }
        }
        try {
            $storage->delete($path);
        } catch (Throwable $e) {
            $failures++;
            Log::warning('Could not delete expired staging object', ['path' => $path, 'error' => $e->getMessage()]);
        }

        return $failures;
    }
}
