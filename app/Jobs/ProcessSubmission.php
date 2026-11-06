<?php

namespace App\Jobs;

use App\Enums\SubmissionStatus;
use App\Enums\SubmissionType;
use App\Media\AssetDraft;
use App\Media\FileSignature;
use App\Media\MediaProcessingFailed;
use App\Media\Processors\AudioProcessor;
use App\Media\Processors\ImageProcessor;
use App\Media\Processors\MediaProcessor;
use App\Media\Processors\VideoProcessor;
use App\Media\Processors\WritingProcessor;
use App\Models\Submission;
use App\Models\UploadSession;
use App\Storage\ObjectStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns a submission's consumed upload(s) into media assets and publishes it.
 *
 * Errors propagate so the worker retries; failed() (after the last try) marks the
 * submission failed and cleans up. A permanent MediaProcessingFailed (e.g. the file's
 * real type does not match) fails the job at once.
 */
class ProcessSubmission implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public int $timeout;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Submission $submission,
        public ?string $uploadSessionId = null,
        public ?string $coverUploadSessionId = null,
    ) {
        $isVideo = $submission->type === SubmissionType::Video;
        $this->onQueue($isVideo ? 'video' : 'media');
        $this->timeout = $isVideo ? VideoProcessor::TRANSCODE_TIMEOUT + 300 : AudioProcessor::TRANSCODE_TIMEOUT * 2 + 300;
    }

    public function handle(ObjectStorage $storage): void
    {
        $submission = $this->submission;
        if ($submission->status !== SubmissionStatus::Processing) {
            return; // already handled (e.g. a duplicate job)
        }

        $dir = sys_get_temp_dir().'/openpaws-job-'.Str::random(20);
        File::ensureDirectoryExists($dir, 0700);

        try {
            $main = $this->download($storage, $this->uploadSessionId, "{$dir}/upload");
            $cover = $this->download($storage, $this->coverUploadSessionId, "{$dir}/cover-upload");

            $drafts = $this->processor($submission->type)->process($submission, $main, $cover);
            $rows = array_map(fn (AssetDraft $d) => $this->store($storage, $submission, $d), $drafts);

            DB::transaction(function () use ($submission, $rows) {
                $submission->assets()->createMany($rows);
                $submission->forceFill(['status' => SubmissionStatus::Published, 'published_at' => now()])->save();
            });
        } catch (MediaProcessingFailed $e) {
            if ($e->permanent && $this->job !== null) {
                $this->fail($e); // not worth retrying; the worker calls failed()

                return;
            }

            throw $e;
        } finally {
            File::deleteDirectory($dir);
        }

        $this->deleteStaging($storage);
    }

    /** Called by the worker once the job has failed for good. */
    public function failed(?Throwable $e): void
    {
        $submission = $this->submission;

        $flipped = Submission::withTrashed()->whereKey($submission->getKey())
            ->where('status', SubmissionStatus::Processing->value)
            ->update(['status' => SubmissionStatus::Failed->value, 'updated_at' => now()]);
        $submission->status = SubmissionStatus::Failed;

        Log::error('Submission processing failed', [
            'submission_id' => $submission->getKey(),
            'public_id' => $submission->public_id,
            'exception' => $e?->getMessage(),
        ]);

        $storage = app(ObjectStorage::class);
        try {
            $this->deleteStaging($storage);
            if ($flipped > 0) {
                $storage->deletePrefix("media/{$submission->public_id}/");
            }
        } catch (Throwable $cleanup) {
            Log::warning('Cleanup after failed processing did not complete', [
                'submission_id' => $submission->getKey(),
                'exception' => $cleanup->getMessage(),
            ]);
        }
    }

    private function processor(SubmissionType $type): MediaProcessor
    {
        return app(match ($type) {
            SubmissionType::Image => ImageProcessor::class,
            SubmissionType::Audio => AudioProcessor::class,
            SubmissionType::Video => VideoProcessor::class,
            SubmissionType::Writing => WritingProcessor::class,
        });
    }

    private function download(ObjectStorage $storage, ?string $sessionId, string $target): ?string
    {
        if ($sessionId === null) {
            return null;
        }
        $session = UploadSession::find($sessionId)
            ?? throw new MediaProcessingFailed("Upload session {$sessionId} no longer exists.", permanent: true);

        $tmp = $storage->downloadToTemp($session->path);
        if (! rename($tmp, $target)) {
            @unlink($tmp);
            throw new MediaProcessingFailed('Could not move the downloaded upload into the job directory.');
        }

        return $target;
    }

    /** Uploads a draft to media/{public_id}/{role}.{ext} and returns its media_assets row. */
    private function store(ObjectStorage $storage, Submission $submission, AssetDraft $draft): array
    {
        $path = "media/{$submission->public_id}/{$draft->role->value}.".FileSignature::extension($draft->mime);
        $storage->putFile($path, $draft->localPath, $draft->mime);

        return [
            'role' => $draft->role,
            'disk' => 's3',
            'path' => $path,
            'mime' => $draft->mime,
            'size_bytes' => filesize($draft->localPath),
            'width' => $draft->width,
            'height' => $draft->height,
            'duration_ms' => $draft->durationMs,
            'checksum_sha256' => hash_file('sha256', $draft->localPath),
        ];
    }

    private function deleteStaging(ObjectStorage $storage): void
    {
        $ids = array_filter([$this->uploadSessionId, $this->coverUploadSessionId]);
        foreach (UploadSession::whereKey($ids)->get() as $session) {
            $storage->delete($session->path);
        }
    }
}
