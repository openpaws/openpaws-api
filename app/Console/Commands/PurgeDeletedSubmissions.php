<?php

namespace App\Console\Commands;

use App\Media\MediaPaths;
use App\Models\Profile;
use App\Models\Submission;
use App\Models\Tag;
use App\Storage\ObjectStorage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('submissions:purge-deleted')]
#[Description('Permanently delete submissions soft-deleted more than the retention period ago')]
class PurgeDeletedSubmissions extends Command
{
    public function handle(ObjectStorage $storage): int
    {
        $purged = 0;
        $failed = 0;
        $storageFailures = 0;

        Submission::onlyTrashed()
            ->where('deleted_at', '<', now()->subDays((int) config('openpaws.purge_days')))
            ->chunkById(100, function ($submissions) use ($storage, &$purged, &$failed, &$storageFailures) {
                foreach ($submissions as $submission) {
                    try {
                        if (! $this->purge($submission)) {
                            continue;
                        }
                        $purged++;
                    } catch (Throwable $e) {
                        $failed++;
                        Log::error('Could not purge submission', ['public_id' => $submission->public_id, 'error' => $e->getMessage()]);

                        continue;
                    }

                    // After the commit: search entry, then stored media.
                    try {
                        $submission->unsearchable();
                    } catch (Throwable $e) {
                        $storageFailures++;
                        Log::warning('Could not remove purged submission from search', ['public_id' => $submission->public_id, 'error' => $e->getMessage()]);
                    }
                    foreach (MediaPaths::allPrefixes($submission) as $prefix) {
                        try {
                            $storage->deletePrefix($prefix);
                        } catch (Throwable $e) {
                            $storageFailures++;
                            Log::warning('Could not delete purged submission media', ['public_id' => $submission->public_id, 'prefix' => $prefix, 'error' => $e->getMessage()]);
                        }
                    }
                }
            });

        $message = "submissions:purge-deleted purged {$purged} submission(s), {$failed} failed, {$storageFailures} storage/search failure(s).";
        Log::info($message);
        $this->info($message);

        return self::SUCCESS;
    }

    /** Returns false when the submission was restored or already purged meanwhile. */
    private function purge(Submission $stale): bool
    {
        return DB::transaction(function () use ($stale) {
            $submission = Submission::onlyTrashed()->whereKey($stale->id)->lockForUpdate()->first();
            if ($submission === null) {
                return false;
            }

            $tagIds = DB::table('submission_tag')->where('submission_id', $submission->id)->pluck('tag_id')->all();

            $submission->assets()->delete();
            DB::table('submission_tag')->where('submission_id', $submission->id)->delete();
            DB::table('folder_submission')->where('submission_id', $submission->id)->delete();

            Profile::query()->whereJsonContains('pinned_submission_ids', $submission->public_id)->get()
                ->each(function (Profile $profile) use ($submission) {
                    $profile->pinned_submission_ids = array_values(array_filter(
                        $profile->pinned_submission_ids ?? [],
                        fn ($id) => (string) $id !== $submission->public_id,
                    ));
                    $profile->save();
                });

            $submission->forceDelete();
            Tag::recount($tagIds);

            return true;
        });
    }
}
