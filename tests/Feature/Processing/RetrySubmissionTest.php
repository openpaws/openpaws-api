<?php

use App\Enums\SubmissionStatus;
use App\Enums\UploadStatus;
use App\Jobs\ProcessSubmission;
use App\Models\MediaAsset;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function failedSubmission(User $u, string $type = 'image'): Submission
{
    return Submission::factory()->for($u)->create(['type' => $type, 'status' => 'failed', 'published_at' => null]);
}

it('retry: only failed submissions, with a new uploaded session, go back to processing', function () {
    Queue::fake();
    $u = User::factory()->create();
    $s = failedSubmission($u);
    MediaAsset::create([
        'owner_type' => 'submission', 'owner_id' => $s->id, 'role' => 'thumb_sm', 'disk' => 's3',
        'path' => "public/media/{$s->public_id}/thumb_sm.webp", 'mime' => 'image/webp', 'size_bytes' => 1,
    ]);
    $this->storage->simulateUpload("public/media/{$s->public_id}/thumb_sm.webp", mediaFixture('image.jpg'));
    $this->storage->simulateUpload("private/media/{$s->public_id}/thumb_md.webp", mediaFixture('image.jpg'));
    $session = makeUploadSession($u, 'image');

    $this->actingAs($u, 'sanctum')->postJson("/api/v1/submissions/{$s->public_id}/retry", ['upload_session_id' => $session->id])
        ->assertOk()->assertJsonPath('data.status', 'processing');

    expect($s->fresh()->status)->toBe(SubmissionStatus::Processing)
        ->and($s->assets()->count())->toBe(0)
        ->and($this->storage->exists("public/media/{$s->public_id}/thumb_sm.webp"))->toBeFalse()
        ->and($this->storage->exists("private/media/{$s->public_id}/thumb_md.webp"))->toBeFalse()
        ->and($session->fresh()->status)->toBe(UploadStatus::Consumed);
    Queue::assertPushed(ProcessSubmission::class, fn ($job) => $job->submission->is($s)
        && $job->uploadSessionId === $session->id && $job->queue === 'media');

    // Now processing (not failed): retry is refused.
    $again = makeUploadSession($u, 'image');
    $this->postJson("/api/v1/submissions/{$s->public_id}/retry", ['upload_session_id' => $again->id])
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_state');
    $published = Submission::factory()->for($u)->create();
    $this->postJson("/api/v1/submissions/{$published->public_id}/retry", ['upload_session_id' => $again->id])
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_state');
    expect($again->fresh()->status)->toBe(UploadStatus::Uploaded);
    Queue::assertPushed(ProcessSubmission::class, 1);
});

it('retry: session rules match creation', function () {
    Queue::fake();
    $u = User::factory()->create();
    $s = failedSubmission($u);
    $retry = fn ($id) => $this->actingAs($u, 'sanctum')->postJson("/api/v1/submissions/{$s->public_id}/retry", ['upload_session_id' => $id]);

    $retry(makeUploadSession($u, 'image', 'pending')->id)->assertStatus(422)->assertJsonPath('error.code', 'upload_not_complete');
    $retry(makeUploadSession($u, 'image', 'consumed')->id)->assertStatus(422)->assertJsonPath('error.code', 'upload_already_used');
    $retry(makeUploadSession($u, 'image', 'expired')->id)->assertStatus(422)->assertJsonPath('error.code', 'upload_already_used');
    $retry(makeUploadSession($u, 'audio')->id)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    $retry(makeUploadSession(User::factory()->create(), 'image')->id)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    $retry(null)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    expect($s->fresh()->status)->toBe(SubmissionStatus::Failed);
    assertNoAppJobsPushed();
});

it('retry: owner only (403 for visible non-owner, 404 when not visible)', function () {
    Queue::fake();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $failed = failedSubmission($owner);
    $visible = Submission::factory()->for($owner)->create();
    $session = makeUploadSession($other, 'image');

    $this->actingAs($other, 'sanctum')->postJson("/api/v1/submissions/{$failed->public_id}/retry", ['upload_session_id' => $session->id])
        ->assertNotFound();
    $this->postJson("/api/v1/submissions/{$visible->public_id}/retry", ['upload_session_id' => $session->id])
        ->assertForbidden()->assertJsonPath('error.code', 'forbidden');

    app('auth')->forgetGuards();
    $this->postJson("/api/v1/submissions/{$failed->public_id}/retry", ['upload_session_id' => $session->id])->assertUnauthorized();
    assertNoAppJobsPushed();
});

it('retry: writing retries take an optional document session and video uses the video queue', function () {
    Queue::fake();
    $u = User::factory()->create();
    $w = failedSubmission($u, 'writing');
    $v = failedSubmission($u, 'video');

    $this->actingAs($u, 'sanctum')->postJson("/api/v1/submissions/{$w->public_id}/retry", [])->assertOk();
    $this->postJson("/api/v1/submissions/{$v->public_id}/retry", ['upload_session_id' => makeUploadSession($u, 'video')->id])->assertOk();

    Queue::assertPushed(ProcessSubmission::class, fn ($job) => $job->submission->is($w) && $job->uploadSessionId === null);
    Queue::assertPushed(ProcessSubmission::class, fn ($job) => $job->submission->is($v) && $job->queue === 'video');
});

it('retry: end to end through the queue republishes the submission', function () {
    $u = User::factory()->create();
    $s = failedSubmission($u);
    $session = stagedSession($u, 'image', 'image.jpg');
    $session->update(['status' => 'uploaded']);

    $this->actingAs($u, 'sanctum')->postJson("/api/v1/submissions/{$s->public_id}/retry", ['upload_session_id' => $session->id])
        ->assertOk();

    expect($s->fresh()->status)->toBe(SubmissionStatus::Published)->and($s->assets()->count())->toBe(5);
});
