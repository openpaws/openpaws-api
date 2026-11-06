<?php

use App\Enums\AssetRole;
use App\Enums\UploadKind;
use App\Enums\UploadStatus;
use App\Models\Folder;
use App\Models\Profile;
use App\Models\Submission;
use App\Models\Tag;
use App\Models\UploadSession;
use App\Models\User;
use App\Storage\FakeObjectStorage;
use App\Storage\ObjectStorage;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

function cleanupSession(array $over = []): UploadSession
{
    $user = User::factory()->create();

    return UploadSession::create($over + [
        'user_id' => $user->id, 'kind' => UploadKind::Image, 'status' => UploadStatus::Pending,
        'filename' => 'a.png', 'mime' => 'image/png', 'size' => 10,
        'path' => 'staging/'.fake()->uuid().'/a.png',
        'expires_at' => now()->subHour(),
    ]);
}

function putObject($test, string $path): void
{
    $f = tempnam(sys_get_temp_dir(), 'cl');
    file_put_contents($f, 'x');
    app(ObjectStorage::class)->simulateUpload($path, $f);
}

it('expires stale sessions and deletes staging', function () {
    $pending = cleanupSession();
    $uploaded = cleanupSession(['status' => UploadStatus::Uploaded]);
    putObject($this, $pending->path);
    putObject($this, $uploaded->path);

    $this->artisan('uploads:expire')->assertSuccessful();

    expect($pending->fresh()->status)->toBe(UploadStatus::Expired)
        ->and($uploaded->fresh()->status)->toBe(UploadStatus::Expired)
        ->and($this->storage->exists($pending->path))->toBeFalse()
        ->and($this->storage->exists($uploaded->path))->toBeFalse();
});

it('keeps fresh sessions', function () {
    $fresh = cleanupSession(['expires_at' => now()->addHours(2)]);
    putObject($this, $fresh->path);

    $this->artisan('uploads:expire')->assertSuccessful();

    expect($fresh->fresh()->status)->toBe(UploadStatus::Pending)
        ->and($this->storage->exists($fresh->path))->toBeTrue();
});

it('aborts the multipart upload of an expired session', function () {
    $id = $this->storage->createMultipart('staging/m/v.mp4', 'video/mp4');
    cleanupSession(['path' => 'staging/m/v.mp4', 'multipart' => true, 'multipart_upload_id' => $id, 'kind' => UploadKind::Video]);

    $this->artisan('uploads:expire')->assertSuccessful();

    expect($this->storage->multiparts)->not->toHaveKey($id);
});

it('does not expire a session consumed concurrently', function () {
    $s = cleanupSession(['status' => UploadStatus::Uploaded]);
    putObject($this, $s->path);

    $armed = true;
    DB::beforeExecuting(function (string $sql) use (&$armed, $s) {
        if ($armed && str_contains($sql, 'upload_sessions') && str_contains(strtolower($sql), 'for update')) {
            $armed = false;
            DB::table('upload_sessions')->where('id', $s->id)->update(['status' => 'consumed']);
        }
    });

    try {
        $this->artisan('uploads:expire')->assertSuccessful();
    } finally {
        $armed = false;
    }

    expect($s->fresh()->status)->toBe(UploadStatus::Consumed)
        ->and($this->storage->exists($s->path))->toBeTrue();
});

it('deletes leftover staging of consumed sessions only after the sweep threshold', function () {
    $old = cleanupSession(['status' => UploadStatus::Consumed]);
    $mid = cleanupSession(['status' => UploadStatus::Consumed]);
    DB::table('upload_sessions')->where('id', $old->id)->update(['updated_at' => now()->subHours(73)]);
    DB::table('upload_sessions')->where('id', $mid->id)->update(['updated_at' => now()->subHours(48)]);
    putObject($this, $old->path);
    putObject($this, $mid->path);

    $this->artisan('uploads:expire')->assertSuccessful();

    expect($this->storage->exists($old->path))->toBeFalse()
        ->and($old->fresh()->status)->toBe(UploadStatus::Consumed)
        ->and($this->storage->exists($mid->path))->toBeTrue();
});

it('keeps going when a storage delete fails during expiry', function () {
    $a = cleanupSession();
    $b = cleanupSession();
    putObject($this, $b->path);
    $failPath = $a->path;
    $storage = new class($failPath) extends FakeObjectStorage
    {
        public function __construct(private string $failPath)
        {
            parent::__construct();
        }

        public function delete(string $path): void
        {
            if ($path === $this->failPath) {
                throw new RuntimeException('boom');
            }
            parent::delete($path);
        }
    };
    $this->app->instance(ObjectStorage::class, $storage);

    $this->artisan('uploads:expire')->assertSuccessful();

    expect($a->fresh()->status)->toBe(UploadStatus::Expired)
        ->and($b->fresh()->status)->toBe(UploadStatus::Expired)
        ->and($this->storage->exists($b->path))->toBeFalse();
});

function trashedSubmission($test, int $daysAgo, array $attrs = []): Submission
{
    $s = Submission::factory()->create($attrs);
    $s->delete();
    DB::table('submissions')->where('id', $s->id)->update(['deleted_at' => now()->subDays($daysAgo)]);

    return $s->fresh() ?? Submission::withTrashed()->find($s->id);
}

it('purges only submissions trashed over 30 days', function () {
    $old = trashedSubmission($this, 31);
    $recent = trashedSubmission($this, 29);
    $live = Submission::factory()->create();

    $this->artisan('submissions:purge-deleted')->assertSuccessful();

    expect(Submission::withTrashed()->whereKey($old->id)->exists())->toBeFalse()
        ->and(Submission::withTrashed()->whereKey($recent->id)->exists())->toBeTrue()
        ->and(Submission::whereKey($live->id)->exists())->toBeTrue();
});

it('removes media, search entry, pivots, pins and recounts tags on purge', function () {
    $old = trashedSubmission($this, 40);
    $other = Submission::factory()->create();
    $tag = Tag::create(['slug' => 'fox', 'usage_count' => 5]);
    $old->tags()->attach($tag->id);
    $old->assets()->create(['role' => AssetRole::Original, 'disk' => 's3', 'path' => "public/media/{$old->public_id}/original.png", 'mime' => 'image/png', 'size_bytes' => 1]);
    $folder = Folder::where('user_id', $old->user_id)->first();
    expect(DB::table('folder_submission')->where('submission_id', $old->id)->exists())->toBeTrue();

    $profile = Profile::forceCreate(['user_id' => $old->user_id, 'pinned_submission_ids' => [$old->public_id, $other->public_id]]);

    putObject($this, "public/media/{$old->public_id}/original.png");
    putObject($this, "private/media/{$old->public_id}/original.png");
    putObject($this, "public/media/{$other->public_id}/original.png");

    $this->artisan('submissions:purge-deleted')->assertSuccessful();

    expect($this->storage->exists("public/media/{$old->public_id}/original.png"))->toBeFalse()
        ->and($this->storage->exists("private/media/{$old->public_id}/original.png"))->toBeFalse()
        ->and($this->storage->exists("public/media/{$other->public_id}/original.png"))->toBeTrue()
        ->and(DB::table('media_assets')->where('owner_id', $old->id)->count())->toBe(0)
        ->and(DB::table('submission_tag')->where('submission_id', $old->id)->count())->toBe(0)
        ->and(DB::table('folder_submission')->where('submission_id', $old->id)->count())->toBe(0)
        ->and($tag->fresh()->usage_count)->toBe(0)
        ->and($profile->fresh()->pinned_submission_ids)->toBe([$other->public_id]);
});

it('keeps purging when one submission fails', function () {
    $a = trashedSubmission($this, 40);
    $b = trashedSubmission($this, 40);
    putObject($this, "public/media/{$a->public_id}/o.png");
    putObject($this, "public/media/{$b->public_id}/o.png");
    $bad = "public/media/{$a->public_id}/";
    $storage = new class($bad) extends FakeObjectStorage
    {
        public function __construct(private string $bad)
        {
            parent::__construct();
        }

        public function deletePrefix(string $prefix): void
        {
            if ($prefix === $this->bad) {
                throw new RuntimeException('boom');
            }
            parent::deletePrefix($prefix);
        }
    };
    $this->app->instance(ObjectStorage::class, $storage);

    $this->artisan('submissions:purge-deleted')->assertSuccessful();

    expect($storage->exists("public/media/{$b->public_id}/o.png"))->toBeFalse()
        ->and(Submission::withTrashed()->whereKey($b->id)->exists())->toBeFalse();
});

it('schedules both commands daily', function () {
    $events = collect(app(Schedule::class)->events());
    foreach (['uploads:expire', 'submissions:purge-deleted'] as $cmd) {
        $e = $events->first(fn ($e) => str_contains($e->command, $cmd));
        expect($e)->not->toBeNull()
            ->and($e->expression)->toBe('0 0 * * *')
            ->and($e->withoutOverlapping)->toBeTrue()
            ->and($e->onOneServer)->toBeTrue();
    }
});
