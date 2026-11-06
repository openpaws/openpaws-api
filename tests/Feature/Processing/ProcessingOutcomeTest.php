<?php

use App\Enums\SubmissionStatus;
use App\Jobs\ProcessSubmission;
use App\Media\MediaProcessingFailed;
use App\Models\User;
use Illuminate\Support\Facades\Log;

it('successful processing publishes, sets published_at, and removes staging', function () {
    $u = User::factory()->create();
    $s = processingSubmission($u, 'image');
    $session = stagedSession($u, 'image', 'image.jpg');

    expect(runProcessing($s, $session))->toBeNull();

    $s->refresh();
    expect($s->status)->toBe(SubmissionStatus::Published)
        ->and($s->published_at)->not->toBeNull()
        ->and($s->isPublished())->toBeTrue()
        ->and($this->storage->exists($session->path))->toBeFalse()
        ->and($s->assets()->count())->toBe(5);
});

it('rejects fake.png (php content) as type mismatch → failed', function () {
    Log::spy();
    $u = User::factory()->create();
    $s = processingSubmission($u, 'image');
    $session = stagedSession($u, 'image', 'fake.png');

    // Through the (sync) queue: a mismatch fails the job at once, without retries.
    ProcessSubmission::dispatch($s, $session->id);

    expect($s->fresh()->status)->toBe(SubmissionStatus::Failed)
        ->and($s->assets()->count())->toBe(0)
        ->and($this->storage->exists($session->path))->toBeFalse();
    Log::shouldHaveReceived('error')->withArgs(fn ($msg, $ctx) => ($ctx['submission_id'] ?? null) === $s->id);
});

it('rejects an image whose cover is not an image', function () {
    $u = User::factory()->create();
    $s = processingSubmission($u, 'audio');
    $main = stagedSession($u, 'audio', 'audio.mp3');
    $cover = stagedSession($u, 'cover', 'fake.png');

    expect(runProcessing($s, $main, $cover))->toBeInstanceOf(MediaProcessingFailed::class);

    expect($s->fresh()->status)->toBe(SubmissionStatus::Failed)
        ->and($this->storage->exists($main->path))->toBeFalse()
        ->and($this->storage->exists($cover->path))->toBeFalse();
});

it('truncated.mp4 and junk.mp3 end in failed, staging deleted, never stuck in processing', function (string $type, string $fixture) {
    $u = User::factory()->create();
    $s = processingSubmission($u, $type);
    $session = stagedSession($u, $type, $fixture);

    expect(runProcessing($s, $session))->toBeInstanceOf(Throwable::class);

    $s->refresh();
    expect($s->status)->toBe(SubmissionStatus::Failed)
        ->and($s->published_at)->toBeNull()
        ->and($s->assets()->count())->toBe(0)
        ->and($this->storage->exists($session->path))->toBeFalse()
        ->and(glob(storedObject("public/media/{$s->public_id}/*")) ?: [])->toBe([])
        ->and(glob(storedObject("private/media/{$s->public_id}/*")) ?: [])->toBe([]);
})->with([
    'truncated video' => ['video', 'truncated.mp4'],
    'junk audio' => ['audio', 'junk.mp3'],
]);

it('failed() removes partially uploaded media under both prefixes', function () {
    $u = User::factory()->create();
    $s = processingSubmission($u, 'image');
    $session = stagedSession($u, 'image', 'image.jpg');
    $this->storage->simulateUpload("public/media/{$s->public_id}/thumb_sm.webp", mediaFixture('image.jpg'));
    $this->storage->simulateUpload("private/media/{$s->public_id}/thumb_md.webp", mediaFixture('image.jpg'));

    (new ProcessSubmission($s, $session->id))->failed(new RuntimeException('boom'));

    expect($this->storage->exists("public/media/{$s->public_id}/thumb_sm.webp"))->toBeFalse()
        ->and($this->storage->exists("private/media/{$s->public_id}/thumb_md.webp"))->toBeFalse()
        ->and($this->storage->exists($session->path))->toBeFalse()
        ->and($s->fresh()->status)->toBe(SubmissionStatus::Failed);
});
