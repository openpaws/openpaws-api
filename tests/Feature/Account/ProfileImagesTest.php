<?php

use App\Enums\UploadStatus;
use App\Models\User;
use App\Storage\ObjectStorage;

function stagedProfileSession(User $u, string $kind, string $fixture = 'image.png')
{
    $s = makeUploadSession($u, $kind);
    app(ObjectStorage::class)->simulateUpload($s->path, mediaFixture($fixture));

    return $s;
}

function localCopy(string $path): string
{
    return app(ObjectStorage::class)->downloadToTemp($path);
}

it('sets avatar 256x256 webp', function () {
    $u = User::factory()->create();
    $s = stagedProfileSession($u, 'avatar');

    $this->actingAs($u, 'sanctum')->putJson('/api/v1/me/avatar', ['upload_session_id' => $s->id])
        ->assertStatus(202)->assertJsonPath('data.username', $u->username);

    $u->refresh();
    expect($u->avatar_path)->toMatch('#^public/users/'.$u->id.'/avatar-[0-9a-f]{16}\.webp$#');
    $info = getimagesize(localCopy($u->avatar_path));
    expect($info[0])->toBe(256)->and($info[1])->toBe(256)->and($info['mime'])->toBe('image/webp');
    expect($s->fresh()->status)->toBe(UploadStatus::Consumed);
    expect(app(ObjectStorage::class)->exists($s->path))->toBeFalse();

    $this->actingAs($u, 'sanctum')->getJson('/api/v1/me')
        ->assertJsonPath('data.avatar_url', app(ObjectStorage::class)->publicUrl($u->avatar_path));
});

it('sets banner 1600x400', function () {
    $u = User::factory()->create();
    $s = stagedProfileSession($u, 'banner');

    $this->actingAs($u, 'sanctum')->putJson('/api/v1/me/banner', ['upload_session_id' => $s->id])->assertStatus(202);

    $u->refresh();
    expect($u->banner_path)->toMatch('#^public/users/'.$u->id.'/banner-[0-9a-f]{16}\.webp$#');
    $info = getimagesize(localCopy($u->banner_path));
    expect($info[0])->toBe(1600)->and($info[1])->toBe(400);
    $this->getJson("/api/v1/users/{$u->username}")
        ->assertJsonPath('user.banner_url', app(ObjectStorage::class)->publicUrl($u->banner_path));
});

it('strips EXIF from the result', function () {
    $u = User::factory()->create();
    $s = stagedProfileSession($u, 'avatar', 'gps.jpg');
    $this->actingAs($u, 'sanctum')->putJson('/api/v1/me/avatar', ['upload_session_id' => $s->id])->assertStatus(202);

    $im = new Imagick(localCopy($u->refresh()->avatar_path));
    expect($im->getImageProperties('exif:GPS*'))->toBeEmpty();
});

it('rejects session of wrong kind', function () {
    $u = User::factory()->create();
    $s = stagedProfileSession($u, 'banner');
    $this->actingAs($u, 'sanctum')->putJson('/api/v1/me/avatar', ['upload_session_id' => $s->id])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['fields' => ['upload_session_id']]]);
    expect($u->refresh()->avatar_path)->toBeNull()->and($s->fresh()->status)->toBe(UploadStatus::Uploaded);
});

it('rejects another user\'s session', function () {
    $u = User::factory()->create();
    $s = stagedProfileSession(User::factory()->create(), 'avatar');
    $this->actingAs($u, 'sanctum')->putJson('/api/v1/me/avatar', ['upload_session_id' => $s->id])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['upload_session_id']]]);
});

it('rejects pending and consumed sessions', function () {
    $u = User::factory()->create();
    $p = makeUploadSession($u, 'avatar', 'pending');
    $c = makeUploadSession($u, 'avatar', 'consumed');
    $this->actingAs($u, 'sanctum')->putJson('/api/v1/me/avatar', ['upload_session_id' => $p->id])
        ->assertStatus(422)->assertJsonPath('error.code', 'upload_not_complete');
    $this->actingAs($u, 'sanctum')->putJson('/api/v1/me/avatar', ['upload_session_id' => $c->id])
        ->assertStatus(422)->assertJsonPath('error.code', 'upload_already_used');
});

it('requires a verified email', function () {
    $u = User::factory()->unverified()->create();
    $s = stagedProfileSession($u, 'avatar');
    $this->actingAs($u, 'sanctum')->putJson('/api/v1/me/avatar', ['upload_session_id' => $s->id])
        ->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
});

it('replacing avatar deletes the old object', function () {
    $u = User::factory()->create();
    $storage = app(ObjectStorage::class);
    $first = stagedProfileSession($u, 'avatar');
    $this->actingAs($u, 'sanctum')->putJson('/api/v1/me/avatar', ['upload_session_id' => $first->id])->assertStatus(202);
    $old = $u->refresh()->avatar_path;
    expect($storage->exists($old))->toBeTrue();

    $second = stagedProfileSession($u, 'avatar', 'image.jpg');
    $this->actingAs($u, 'sanctum')->putJson('/api/v1/me/avatar', ['upload_session_id' => $second->id])->assertStatus(202);

    $new = $u->refresh()->avatar_path;
    expect($new)->not->toBe($old)->and($storage->exists($new))->toBeTrue()->and($storage->exists($old))->toBeFalse();
});

it('rejects non-image content', function () {
    $u = User::factory()->create();
    $s = stagedProfileSession($u, 'avatar', 'fake.png');
    $this->actingAs($u, 'sanctum')->putJson('/api/v1/me/avatar', ['upload_session_id' => $s->id])->assertStatus(202);

    expect($u->refresh()->avatar_path)->toBeNull()
        ->and(app(ObjectStorage::class)->exists($s->path))->toBeFalse();
});
