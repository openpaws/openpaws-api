<?php

use App\Enums\SubmissionStatus;
use App\Enums\UploadStatus;
use App\Jobs\ProcessSubmission;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function createSubmission($test, User $u, array $over = [])
{
    return $test->actingAs($u, 'sanctum')->postJson('/api/v1/submissions', $over + [
        'type' => 'image', 'title' => 'Sunset fox', 'description' => '**hi**', 'rating' => 'general',
        'visibility' => 'public', 'folder' => 'gallery', 'tags' => ['fox', 'sunset'],
    ]);
}

it('creates an image submission from an uploaded session and dispatches processing', function () {
    Queue::fake();
    $u = User::factory()->create();
    $s = makeUploadSession($u, 'image');

    createSubmission($this, $u, ['upload_session_id' => $s->id])
        ->assertCreated()
        ->assertJsonPath('data.type', 'image')
        ->assertJsonPath('data.title', 'Sunset fox')
        ->assertJsonPath('data.status', 'processing')
        ->assertJsonPath('data.rating', 'general')
        ->assertJsonPath('data.visibility', 'public')
        ->assertJsonPath('data.description_html', '<p><strong>hi</strong></p>')
        ->assertJsonPath('data.tags', ['fox', 'sunset'])
        ->assertJsonPath('data.folders.0.kind', 'gallery')
        ->assertJsonPath('data.media', [])
        ->assertJsonPath('data.views_count', 0)
        ->assertJsonPath('data.published_at', null)
        ->assertJsonPath('data.user.username', $u->username)
        ->assertJsonMissingPath('data.body');

    $sub = Submission::sole();
    expect($sub->status)->toBe(SubmissionStatus::Processing)
        ->and($sub->user_id)->toBe($u->id)
        ->and($sub->public_id)->toMatch('/^[a-z0-9]{10}$/');
    expect($s->fresh()->status)->toBe(UploadStatus::Consumed);
    Queue::assertPushed(ProcessSubmission::class, fn ($job) => $job->submission->is($sub));
});

it('rejects a pending (not completed) session with upload_not_complete and creates nothing', function () {
    Queue::fake();
    $u = User::factory()->create();
    $s = makeUploadSession($u, 'image', 'pending');

    createSubmission($this, $u, ['upload_session_id' => $s->id])
        ->assertStatus(422)->assertJsonPath('error.code', 'upload_not_complete');
    expect(Submission::count())->toBe(0)->and($s->fresh()->status)->toBe(UploadStatus::Pending);
    assertNoAppJobsPushed();
});

it('rejects reusing a consumed session with upload_already_used', function (string $status) {
    Queue::fake();
    $u = User::factory()->create();
    $s = makeUploadSession($u, 'image', $status);

    createSubmission($this, $u, ['upload_session_id' => $s->id])
        ->assertStatus(422)->assertJsonPath('error.code', 'upload_already_used');
    expect(Submission::count())->toBe(0);
    assertNoAppJobsPushed();
})->with(['consumed', 'expired']);

it('rejects a second submission from the same session', function () {
    Queue::fake();
    $u = User::factory()->create();
    $s = makeUploadSession($u, 'image');
    createSubmission($this, $u, ['upload_session_id' => $s->id])->assertCreated();
    createSubmission($this, $u, ['upload_session_id' => $s->id])
        ->assertStatus(422)->assertJsonPath('error.code', 'upload_already_used');
    expect(Submission::count())->toBe(1);
});

it('rejects a session whose kind mismatches the type', function () {
    Queue::fake();
    $u = User::factory()->create();
    $s = makeUploadSession($u, 'audio');

    createSubmission($this, $u, ['upload_session_id' => $s->id])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['fields' => ['upload_session_id']]]);
    expect(Submission::count())->toBe(0)->and($s->fresh()->status)->toBe(UploadStatus::Uploaded);
});

it('rejects a session owned by someone else or unknown', function () {
    Queue::fake();
    $u = User::factory()->create();
    $other = makeUploadSession(User::factory()->create(), 'image');

    createSubmission($this, $u, ['upload_session_id' => $other->id])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['fields' => ['upload_session_id']]]);
    createSubmission($this, $u, ['upload_session_id' => (string) Str::uuid()])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['upload_session_id']]]);
    expect(Submission::count())->toBe(0)->and($other->fresh()->status)->toBe(UploadStatus::Uploaded);
});

it('requires an upload session for non-writing types', function (string $type) {
    createSubmission($this, User::factory()->create(), ['type' => $type])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['upload_session_id']]]);
})->with(['image', 'audio', 'video']);

it('writing requires body; upload session optional', function () {
    Queue::fake();
    $u = User::factory()->create();

    createSubmission($this, $u, ['type' => 'writing'])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['body']]]);

    createSubmission($this, $u, ['type' => 'writing', 'body' => 'Once upon a time'])
        ->assertCreated()->assertJsonPath('data.type', 'writing');
    expect(Submission::sole()->body)->toBe('Once upon a time');

    $doc = makeUploadSession($u, 'document');
    createSubmission($this, $u, ['type' => 'writing', 'body' => 'Second', 'upload_session_id' => $doc->id])->assertCreated();
    expect($doc->fresh()->status)->toBe(UploadStatus::Consumed);

    $img = makeUploadSession($u, 'image');
    createSubmission($this, $u, ['type' => 'writing', 'body' => 'Third', 'upload_session_id' => $img->id])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['upload_session_id']]]);
    expect(Submission::count())->toBe(2);
});

it('body is rejected for non-writing types', function () {
    $u = User::factory()->create();
    createSubmission($this, $u, ['upload_session_id' => makeUploadSession($u)->id, 'body' => 'nope'])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['body']]]);
});

it('accepts a cover session for audio, video and writing and consumes it', function (string $type, ?string $kind) {
    Queue::fake();
    $u = User::factory()->create();
    $main = $kind ? makeUploadSession($u, $kind) : null;
    $cover = makeUploadSession($u, 'cover');

    createSubmission($this, $u, array_filter([
        'type' => $type, 'upload_session_id' => $main?->id, 'cover_upload_session_id' => $cover->id,
        'body' => $type === 'writing' ? 'text' : null,
    ]))->assertCreated();
    expect($cover->fresh()->status)->toBe(UploadStatus::Consumed);
    if ($main) {
        expect($main->fresh()->status)->toBe(UploadStatus::Consumed);
    }
})->with([['audio', 'audio'], ['video', 'video'], ['writing', null]]);

it('rejects a cover session for image submissions', function () {
    Queue::fake();
    $u = User::factory()->create();
    $main = makeUploadSession($u, 'image');
    $cover = makeUploadSession($u, 'cover');

    createSubmission($this, $u, ['upload_session_id' => $main->id, 'cover_upload_session_id' => $cover->id])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['cover_upload_session_id']]]);
    expect(Submission::count())->toBe(0)
        ->and($main->fresh()->status)->toBe(UploadStatus::Uploaded)
        ->and($cover->fresh()->status)->toBe(UploadStatus::Uploaded);
});

it('rejects a cover session of the wrong kind or state and leaves the main session untouched', function () {
    Queue::fake();
    $u = User::factory()->create();
    $main = makeUploadSession($u, 'audio');

    createSubmission($this, $u, ['type' => 'audio', 'upload_session_id' => $main->id, 'cover_upload_session_id' => makeUploadSession($u, 'image')->id])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['cover_upload_session_id']]]);
    createSubmission($this, $u, ['type' => 'audio', 'upload_session_id' => $main->id, 'cover_upload_session_id' => makeUploadSession($u, 'cover', 'pending')->id])
        ->assertStatus(422)->assertJsonPath('error.code', 'upload_not_complete');
    createSubmission($this, $u, ['type' => 'audio', 'upload_session_id' => $main->id, 'cover_upload_session_id' => makeUploadSession($u, 'cover', 'consumed')->id])
        ->assertStatus(422)->assertJsonPath('error.code', 'upload_already_used');
    createSubmission($this, $u, ['type' => 'audio', 'upload_session_id' => $main->id, 'cover_upload_session_id' => makeUploadSession(User::factory()->create(), 'cover')->id])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['cover_upload_session_id']]]);

    expect(Submission::count())->toBe(0)->and($main->fresh()->status)->toBe(UploadStatus::Uploaded);
    assertNoAppJobsPushed();
});

it('requires auth and a verified email', function () {
    $this->postJson('/api/v1/submissions', [])->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    $u = User::factory()->unverified()->create();
    createSubmission($this, $u, ['upload_session_id' => makeUploadSession($u)->id])
        ->assertForbidden()->assertExactJson(['error' => ['code' => 'forbidden', 'message' => 'Verify your email address to continue.']]);
    expect(Submission::count())->toBe(0);
});

it('validates title, rating, visibility, folder and tags', function (array $over, string $field) {
    $u = User::factory()->create();
    createSubmission($this, $u, $over + ['upload_session_id' => makeUploadSession($u)->id])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['fields' => [$field]]]);
})->with([
    'missing title' => [['title' => ''], 'title'],
    'long title' => [['title' => str_repeat('a', 151)], 'title'],
    'bad rating' => [['rating' => 'extreme'], 'rating'],
    'bad visibility' => [['visibility' => 'private'], 'visibility'],
    'bad type' => [['type' => 'flash'], 'type'],
    'tags not array' => [['tags' => 'fox'], 'tags'],
    'tag too long' => [['tags' => [str_repeat('a', 101)]], 'tags.0'],
    'too many tags' => [['tags' => array_fill(0, 201, 'a')], 'tags'],
    'folder not string' => [['folder' => ['x']], 'folder'],
    'long description' => [['description' => str_repeat('a', 20001)], 'description'],
]);

it('dispatches processing only after the transaction commits', function () {
    Queue::fake();
    $u = User::factory()->create();
    createSubmission($this, $u, ['upload_session_id' => makeUploadSession($u)->id])->assertCreated();
    Queue::assertPushed(ProcessSubmission::class, fn ($job) => $job->afterCommit === true);
});
