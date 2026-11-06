<?php

use App\Enums\AssetRole;
use App\Enums\Rating;
use App\Jobs\MoveSubmissionMedia;
use App\Media\MediaPaths;
use App\Media\MediaUrls;
use App\Models\Submission;
use App\Models\Tag;
use App\Models\User;
use App\Storage\ObjectStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Gives $s stored assets (objects + rows) under the prefix for its current rating.
 *
 * @param  array<string, array<string, mixed>>  $roles  role => extra columns
 */
function giveAssets(Submission $s, array $roles): void
{
    foreach ($roles as $role => $extra) {
        $path = MediaPaths::prefixFor($s).$role.'-'.bin2hex(random_bytes(8)).'.bin';
        app(ObjectStorage::class)->simulateUpload($path, mediaFixture('image.jpg'));
        $s->assets()->create($extra + [
            'role' => $role, 'disk' => 's3', 'path' => $path, 'mime' => 'image/webp', 'size_bytes' => 123,
        ]);
    }
}

it('general submission media urls are public cdn urls', function () {
    config(['openpaws.cdn_url' => 'https://cdn.example.test/openpaws']);
    $s = Submission::factory()->create();
    giveAssets($s, ['thumb_sm' => ['width' => 200, 'height' => 150], 'original' => ['mime' => 'image/png', 'width' => 2400, 'height' => 1800]]);

    $res = $this->getJson("/api/v1/submissions/{$s->public_id}")->assertOk();

    $thumb = $s->asset(AssetRole::ThumbSm);
    expect($thumb->path)->toStartWith("public/media/{$s->public_id}/");
    $res->assertJsonPath('data.media.thumb_sm', [
        'url' => 'https://cdn.example.test/openpaws/'.$thumb->path,
        'mime' => 'image/webp', 'width' => 200, 'height' => 150, 'size_bytes' => 123,
    ]);
    $res->assertJsonPath('data.media.original.url', 'https://cdn.example.test/openpaws/'.$s->asset(AssetRole::Original)->path)
        ->assertJsonPath('data.media.original.mime', 'image/png');
    expect(array_keys($res->json('data.media')))->toEqualCanonicalizing(['thumb_sm', 'original']);
});

it('mature submission media urls are public cdn urls too', function () {
    $s = Submission::factory()->create(['rating' => Rating::Mature]);
    giveAssets($s, ['thumb_md' => []]);

    $url = MediaUrls::for($s->asset(AssetRole::ThumbMd), $s);
    expect($url)->toBe(rtrim(config('openpaws.cdn_url'), '/').'/'.$s->asset(AssetRole::ThumbMd)->path)
        ->and($url)->toContain('/public/media/');
});

it('adult submission media urls are temporary and expire in 600s', function () {
    $this->freezeTime();
    $viewer = makeAdultUser();
    $s = Submission::factory()->create(['rating' => Rating::Adult]);
    giveAssets($s, ['preview' => ['width' => 10, 'height' => 10]]);
    $preview = $s->asset(AssetRole::Preview);
    expect($preview->path)->toStartWith("private/media/{$s->public_id}/");

    $this->actingAs($viewer, 'sanctum')->getJson("/api/v1/submissions/{$s->public_id}")->assertOk()
        ->assertJsonPath('data.media.preview.url', 'fake://temp/'.$preview->path.'?expires='.(time() + 600));
    expect(MediaUrls::for($preview, $s))->toBe('fake://temp/'.$preview->path.'?expires='.(time() + 600));
});

it('audio/video expose transcode but not original', function (string $type) {
    $s = Submission::factory()->create(['type' => $type]);
    giveAssets($s, [
        'original' => [], 'transcode' => ['duration_ms' => 2000], 'cover' => [], 'thumb_sm' => [],
        ...($type === 'audio' ? ['waveform' => ['mime' => 'application/json']] : []),
    ]);

    $media = $this->getJson("/api/v1/submissions/{$s->public_id}")->assertOk()->json('data.media');

    expect($media)->not->toHaveKey('original')
        ->and($media)->toHaveKeys(['transcode', 'cover', 'thumb_sm'])
        ->and($media['transcode']['duration_ms'])->toBe(2000)
        ->and($media['transcode'])->not->toHaveKeys(['width', 'height']);
    if ($type === 'audio') {
        expect($media['waveform']['mime'])->toBe('application/json');
    }
})->with(['audio', 'video']);

it('writing exposes its document; empty media is an object', function () {
    $w = Submission::factory()->create(['type' => 'writing', 'body' => 'x']);
    giveAssets($w, ['document' => ['mime' => 'application/pdf']]);
    $bare = Submission::factory()->create(['type' => 'writing', 'body' => 'x']);

    $this->getJson("/api/v1/submissions/{$w->public_id}")->assertOk()
        ->assertJsonPath('data.media.document.mime', 'application/pdf');
    $raw = $this->getJson("/api/v1/submissions/{$bare->public_id}")->assertOk()->getContent();
    expect($raw)->toContain('"media":{}');
});

it('stores processed assets under public/ or private/ by rating', function (string $rating, string $scope) {
    $u = User::factory()->create();
    $s = processingSubmission($u, 'image');
    $s->update(['rating' => $rating]);

    expect(runProcessing($s, stagedSession($u, 'image', 'image.jpg')))->toBeNull();

    $s->refresh();
    expect($s->assets()->count())->toBe(5);
    foreach ($s->assets as $a) {
        expect($a->path)->toMatch(mediaPathPattern($s->public_id, $a->role->value, $a->role === AssetRole::Original ? 'jpg' : 'webp', $scope))
            ->and($this->storage->exists($a->path))->toBeTrue();
    }
})->with([
    'general' => ['general', 'public'],
    'mature' => ['mature', 'public'],
    'adult' => ['adult', 'private'],
]);

it('a rating that crosses the adult boundary during processing moves the new assets', function () {
    $u = User::factory()->create();
    $s = processingSubmission($u, 'image'); // general when the job starts
    Submission::whereKey($s->id)->update(['rating' => 'adult']); // PATCHed while processing

    expect(runProcessing($s, stagedSession($u, 'image', 'image.jpg')))->toBeNull();

    $paths = $s->assets()->pluck('path')->all();
    expect($paths)->toHaveCount(5);
    foreach ($paths as $path) {
        expect($path)->toStartWith("private/media/{$s->public_id}/")->and($this->storage->exists($path))->toBeTrue();
    }
});

it('PATCH general→adult moves objects to private/ and back on adult→general', function () {
    $owner = makeAdultUser();
    $s = Submission::factory()->for($owner)->create();
    giveAssets($s, ['thumb_sm' => [], 'original' => []]);
    $before = $s->assets()->pluck('path', 'role')->all();

    $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/submissions/{$s->public_id}", ['rating' => 'adult'])->assertOk();

    $after = $s->assets()->pluck('path', 'role')->all();
    foreach ($before as $role => $old) {
        expect($after[$role])->toBe('private/media/'.$s->public_id.'/'.basename($old))
            ->and($this->storage->exists($after[$role]))->toBeTrue()
            ->and($this->storage->exists($old))->toBeFalse();
    }

    $this->patchJson("/api/v1/submissions/{$s->public_id}", ['rating' => 'general'])->assertOk()
        ->assertJsonPath('data.media.thumb_sm.url', rtrim(config('openpaws.cdn_url'), '/').'/'.$before['thumb_sm']);
    expect($s->assets()->pluck('path', 'role')->all())->toBe($before);
    foreach ($before as $old) {
        expect($this->storage->exists($old))->toBeTrue();
    }
});

it('PATCH only queues a move when the rating crosses the adult boundary', function () {
    Queue::fake();
    $owner = makeAdultUser();
    $s = Submission::factory()->for($owner)->create();
    $this->actingAs($owner, 'sanctum');

    $this->patchJson("/api/v1/submissions/{$s->public_id}", ['rating' => 'mature'])->assertOk();
    $this->patchJson("/api/v1/submissions/{$s->public_id}", ['title' => 'x'])->assertOk();
    Queue::assertNotPushed(MoveSubmissionMedia::class);

    $this->patchJson("/api/v1/submissions/{$s->public_id}", ['rating' => 'adult'])->assertOk();
    Queue::assertPushed(MoveSubmissionMedia::class, fn ($job) => $job->submission->is($s));
    $this->patchJson("/api/v1/submissions/{$s->public_id}", ['rating' => 'mature'])->assertOk();
    Queue::assertPushed(MoveSubmissionMedia::class, 2);
});

it('media urls for a newly-adult item are signed even before the move job ran', function () {
    Queue::fake();
    $this->freezeTime();
    $owner = makeAdultUser();
    $s = Submission::factory()->for($owner)->create();
    giveAssets($s, ['thumb_sm' => []]);
    $path = $s->asset(AssetRole::ThumbSm)->path;

    $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/submissions/{$s->public_id}", ['rating' => 'adult'])->assertOk()
        ->assertJsonPath('data.media.thumb_sm.url', 'fake://temp/'.$path.'?expires='.(time() + 600));
    expect($path)->toStartWith('public/');
    Queue::assertPushed(MoveSubmissionMedia::class);

    $this->getJson("/api/v1/submissions/{$s->public_id}")->assertOk()
        ->assertJsonPath('data.media.thumb_sm.url', 'fake://temp/'.$path.'?expires='.(time() + 600));
});

it('MoveSubmissionMedia is idempotent and tolerates a source already moved', function () {
    $s = Submission::factory()->create();
    giveAssets($s, ['thumb_sm' => [], 'thumb_md' => [], 'original' => []]);
    Submission::whereKey($s->id)->update(['rating' => 'adult']);
    $s->refresh();

    // thumb_md's object was already copied by an earlier, interrupted run (row not yet updated).
    $md = $s->asset(AssetRole::ThumbMd);
    $mdTarget = 'private/media/'.$s->public_id.'/'.basename($md->path);
    $this->storage->move($md->path, $mdTarget);

    (new MoveSubmissionMedia($s))->handle($this->storage);
    $first = $s->assets()->pluck('path', 'role')->all();
    (new MoveSubmissionMedia($s->fresh()))->handle($this->storage);

    expect($s->assets()->pluck('path', 'role')->all())->toBe($first)
        ->and($first['thumb_md'])->toBe($mdTarget);
    foreach ($first as $path) {
        expect($path)->toStartWith("private/media/{$s->public_id}/")->and($this->storage->exists($path))->toBeTrue();
    }
    expect(glob(storedObject("public/media/{$s->public_id}/*")) ?: [])->toBe([]);
});

it('fake storage move renames the object', function () {
    $this->storage->simulateUpload('public/media/x/a.bin', mediaFixture('image.jpg'));
    $this->storage->move('public/media/x/a.bin', 'private/media/x/a.bin');

    expect($this->storage->exists('public/media/x/a.bin'))->toBeFalse()
        ->and($this->storage->exists('private/media/x/a.bin'))->toBeTrue();
});

it('tag page includes media without a query per submission', function () {
    $tag = Tag::create(['slug' => 'fox', 'usage_count' => 1]);
    $make = function () use ($tag) {
        $s = Submission::factory()->create();
        $s->tags()->attach($tag->id);
        giveAssets($s, ['thumb_sm' => []]);
    };
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $res = $this->getJson('/api/v1/tags/fox')->assertOk();
        DB::disableQueryLog();

        return [count(DB::getQueryLog()), $res];
    };

    $make();
    [$one] = $count();
    $make();
    $make();
    [$three, $res] = $count();

    expect($three)->toBe($one)
        ->and($res->json('data.0.media.thumb_sm.url'))->toStartWith(rtrim(config('openpaws.cdn_url'), '/').'/public/media/');
});
