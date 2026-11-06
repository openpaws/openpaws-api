<?php

namespace App\Jobs;

use App\Media\MediaPaths;
use App\Models\MediaAsset;
use App\Models\Submission;
use App\Storage\ObjectStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Moves a submission's asset objects between public/ and private/ after its rating
 * crossed the adult boundary. The target comes from the rating when the job runs, so
 * several quick flips converge on the right place. Idempotent: rows already under the
 * target prefix are skipped, and a missing source is fine when the target exists
 * (an earlier run copied the object but did not get to update the row).
 *
 * Runs on its own `moves` queue (listed first by the worker) so it never waits behind long
 * transcodes. While it is pending, MediaUrls gives adult assets still under public/ no URL.
 * Overlapping runs are released rather than dropped (a dropped run could miss the latest
 * flip); releases do not use up attempts: the job is bounded by retryUntil() and only real
 * exceptions count, via maxExceptions.
 */
class MoveSubmissionMedia implements ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Submission $submission)
    {
        $this->onQueue('moves');
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->submission->getKey()))->releaseAfter(10)->expireAfter(3600)];
    }

    /** Called once the job has failed for good: adult files may be sitting in public/. */
    public function failed(?Throwable $e): void
    {
        $submission = $this->submission->fresh() ?? $this->submission;
        $target = MediaPaths::prefixFor($submission);
        $pending = $submission->assets()->pluck('path')
            ->reject(fn (string $p) => str_starts_with($p, $target))->values()->all();

        Log::critical('Moving submission media failed', [
            'submission_id' => $submission->getKey(),
            'public_id' => $submission->public_id,
            'target_prefix' => $target,
            'paths_not_moved' => $pending,
            'exception' => $e?->getMessage(),
        ]);
    }

    public function handle(ObjectStorage $storage): void
    {
        $submission = $this->submission->fresh(); // includes trashed: their media must move too
        if ($submission === null) {
            return;
        }
        $target = MediaPaths::prefixFor($submission);

        foreach ($submission->assets()->get() as $asset) {
            /** @var MediaAsset $asset */
            if (str_starts_with($asset->path, $target)) {
                continue;
            }
            $to = $target.basename($asset->path);

            if ($storage->exists($asset->path)) {
                $storage->move($asset->path, $to);
            } elseif (! $storage->exists($to)) {
                Log::warning('Media object missing during move', [
                    'submission_id' => $submission->getKey(), 'asset_id' => $asset->getKey(), 'path' => $asset->path,
                ]);
            }

            $asset->forceFill(['path' => $to])->save();
        }
    }
}
