<?php

use App\Jobs\ProcessSubmission;
use App\Models\Submission;
use App\Models\UploadSession;
use App\Models\User;
use App\Storage\ObjectStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Scout\Jobs\MakeSearchable;
use Laravel\Scout\Jobs\RemoveFromSearch;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Unit');

/** An upload session owned by $user in the given kind and status (defaults to a completed image upload). */
function makeUploadSession(User $user, string $kind = 'image', string $status = 'uploaded'): UploadSession
{
    return UploadSession::create([
        'user_id' => $user->id, 'kind' => $kind, 'status' => $status, 'filename' => 'f.bin', 'mime' => 'image/png',
        'size' => 10, 'path' => 'staging/'.Str::uuid().'/f.bin', 'expires_at' => now()->addDay(),
    ]);
}

/** A user who has confirmed 18+ and chose the given max rating. */
function makeAdultUser(string $maxRating = 'adult'): User
{
    return User::factory()->create(['adult_confirmed_at' => now(), 'max_rating' => $maxRating]);
}

/** Absolute path of a media fixture. */
function mediaFixture(string $name): string
{
    return base_path('tests/Fixtures/media/'.$name);
}

/** A consumed upload session whose staged object is the given fixture (as when the job runs). */
function stagedSession(User $user, string $kind, string $fixture): UploadSession
{
    $session = makeUploadSession($user, $kind, 'consumed');
    app(ObjectStorage::class)->simulateUpload($session->path, mediaFixture($fixture));

    return $session;
}

/** A submission waiting for processing. */
function processingSubmission(User $user, string $type): Submission
{
    return Submission::factory()->for($user)->create([
        'type' => $type, 'status' => 'processing', 'published_at' => null,
        'body' => $type === 'writing' ? '# Story' : null,
    ]);
}

/**
 * Runs ProcessSubmission the way the queue worker ends up doing: handle(), and on an
 * exception (after retries are exhausted) failed(). Returns the exception, if any.
 */
function runProcessing(Submission $s, ?UploadSession $main, ?UploadSession $cover = null): ?Throwable
{
    $job = new ProcessSubmission($s, $main?->id, $cover?->id);
    try {
        app()->call([$job, 'handle']);

        return null;
    } catch (Throwable $e) {
        $job->failed($e);

        return $e;
    }
}

/** Local path of an object in the fake object store. */
function storedObject(string $path): string
{
    return storage_path('framework/testing/objects/'.$path);
}

/** Regex for a stored asset path: {public|private}/media/{public_id}/{role}-{16 hex}.{ext}. */
function mediaPathPattern(string $publicId, string $role, string $ext, string $scope = 'public'): string
{
    return '#^'.$scope.'/media/'.preg_quote($publicId, '#').'/'.preg_quote($role, '#').'-[0-9a-f]{16}\.'.preg_quote($ext, '#').'$#';
}

/**
 * Like Queue::assertNothingPushed(), but ignores Scout's own index-sync jobs: Scout is queued
 * (R30), so creating a searchable model in a test pushes one of those.
 */
function assertNoAppJobsPushed(): void
{
    $pushed = array_values(array_diff(
        array_keys(Queue::pushedJobs()),
        [MakeSearchable::class, RemoveFromSearch::class],
    ));
    expect($pushed)->toBe([]);
}
