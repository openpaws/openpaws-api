<?php

use App\Enums\UploadStatus;
use App\Models\UploadSession;
use App\Models\User;
use App\Storage\ObjectStorage;

const UPMB = 1024 * 1024;

function startUploadReq($test, User $u, array $over = [])
{
    return $test->actingAs($u)->postJson('/api/v1/uploads', $over + [
        'kind' => 'image', 'filename' => 'a b.png', 'mime' => 'image/png', 'size' => 1000,
    ]);
}

function uploadFixture(int $bytes): string
{
    $p = tempnam(sys_get_temp_dir(), 'fx');
    file_put_contents($p, str_repeat('x', $bytes));

    return $p;
}

it('creates a single-PUT session under 100 MB', function () {
    $u = User::factory()->create();
    startUploadReq($this, $u)->assertCreated()
        ->assertJsonPath('data.multipart', false)
        ->assertJsonPath('data.status', 'pending')
        ->assertJson(fn ($j) => $j->has('data.url')->has('data.expires_at')->etc())
        ->assertJsonMissingPath('url'); // only data.url, no top-level copy
    expect(array_keys(startUploadReq($this, $u)->json()))->toBe(['data']);
    expect(UploadSession::first()->path)->toStartWith('staging/')->toEndWith('/a_b.png');
});

it('creates a multipart session above 100 MB with 50 MB parts', function () {
    $u = User::factory()->create();
    startUploadReq($this, $u, ['kind' => 'video', 'filename' => 'v.mp4', 'mime' => 'video/mp4', 'size' => 250 * UPMB])
        ->assertCreated()
        ->assertJsonPath('data.multipart', true)
        ->assertJsonPath('data.part_size', 50 * UPMB)
        ->assertJsonPath('data.part_count', 5);

    $id = UploadSession::first()->id;
    $this->actingAs($u)->postJson("/api/v1/uploads/$id/parts", ['part_numbers' => [1, 5]])
        ->assertOk()->assertJsonStructure(['urls' => ['1', '5']]);
    $this->actingAs($u)->postJson("/api/v1/uploads/$id/parts", ['part_numbers' => [6]])->assertStatus(422);
});

it('rejects size over the kind limit', function () {
    startUploadReq($this, User::factory()->create(), ['size' => 50 * UPMB + 1])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['size']]]);
});

it('rejects a declared mime not allowed for the kind', function () {
    startUploadReq($this, User::factory()->create(), ['mime' => 'application/pdf'])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
});

it('requires a verified email to upload', function () {
    startUploadReq($this, User::factory()->unverified()->create())
        ->assertForbidden()->assertExactJson(['error' => ['code' => 'forbidden', 'message' => 'Verify your email address to continue.']]);
});

it('enforces daily quota by count and bytes', function () {
    $u = User::factory()->create();
    for ($i = 0; $i < 100; $i++) {
        UploadSession::create(['user_id' => $u->id, 'kind' => 'image', 'status' => 'pending', 'filename' => 'a', 'mime' => 'image/png',
            'size' => 1, 'path' => "staging/$i/a", 'expires_at' => now()->addDay()]);
    }
    startUploadReq($this, $u)->assertStatus(422)->assertJsonPath('error.code', 'quota_exceeded');

    $b = User::factory()->create();
    UploadSession::create(['user_id' => $b->id, 'kind' => 'video', 'status' => 'pending', 'filename' => 'a', 'mime' => 'video/mp4',
        'size' => 10 * 1024 * UPMB - 10, 'path' => 'staging/x/a', 'expires_at' => now()->addDay()]);
    startUploadReq($this, $b, ['size' => 11])->assertStatus(422)->assertJsonPath('error.code', 'quota_exceeded');
    startUploadReq($this, $b, ['size' => 10])->assertCreated();
});

it('throttles to 30 uploads per hour', function () {
    $u = User::factory()->create();
    for ($i = 0; $i < 30; $i++) {
        startUploadReq($this, $u)->assertCreated();
    }
    startUploadReq($this, $u)->assertStatus(429);
});

it('complete fails with upload_not_complete when the object is missing', function () {
    $u = User::factory()->create();
    $id = startUploadReq($this, $u)->json('data.id');
    $this->actingAs($u)->postJson("/api/v1/uploads/$id/complete")
        ->assertStatus(422)->assertJsonPath('error.code', 'upload_not_complete');
});

it('complete fails when the stored size differs from the declared size', function () {
    $u = User::factory()->create();
    $id = startUploadReq($this, $u)->json('data.id');
    app(ObjectStorage::class)->simulateUpload(UploadSession::find($id)->path, uploadFixture(999));
    $this->actingAs($u)->postJson("/api/v1/uploads/$id/complete")
        ->assertStatus(422)->assertJsonPath('error.code', 'upload_not_complete');
});

it('complete marks uploaded when the object exists with the declared size', function () {
    $u = User::factory()->create();
    $id = startUploadReq($this, $u)->json('data.id');
    app(ObjectStorage::class)->simulateUpload(UploadSession::find($id)->path, uploadFixture(1000));
    $this->actingAs($u)->postJson("/api/v1/uploads/$id/complete")
        ->assertOk()->assertJson(['id' => $id, 'status' => 'uploaded']);
    expect(UploadSession::find($id)->status)->toBe(UploadStatus::Uploaded);
    $this->actingAs($u)->postJson("/api/v1/uploads/$id/complete")
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_state');
});

it("cannot touch another user's upload session", function () {
    $u = User::factory()->create();
    $id = startUploadReq($this, $u)->json('data.id');
    $o = User::factory()->create();
    $this->actingAs($o)->postJson("/api/v1/uploads/$id/complete")->assertNotFound();
    $this->actingAs($o)->postJson("/api/v1/uploads/$id/parts", ['part_numbers' => [1]])->assertNotFound();
});

it('rejects parts for a non-multipart session', function () {
    $u = User::factory()->create();
    $id = startUploadReq($this, $u)->json('data.id');
    $this->actingAs($u)->postJson("/api/v1/uploads/$id/parts", ['part_numbers' => [1]])
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_state');
});
