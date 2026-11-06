<?php

use App\Models\Folder;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

function folderIdsOf(Submission $s): array
{
    return $s->folders()->orderBy('folders.position')->get()->map(fn ($f) => $f->kind->value === 'custom' ? $f->name : $f->kind->value)->all();
}

function folderOf(User $u, string $kind): Folder
{
    return Folder::where('user_id', $u->id)->where('kind', $kind)->firstOrFail();
}

it('registration creates gallery and scraps folders', function () {
    Mail::fake();
    $this->withHeader('Origin', 'http://localhost:3000')->withHeader('Referer', 'http://localhost:3000/')->postJson('/api/v1/auth/register', [
        'username' => 'newfox', 'display_name' => 'New Fox', 'email' => 'new@example.com',
        'password' => 'correct-horse-9', 'password_confirmation' => 'correct-horse-9',
    ])->assertCreated();
    $u = User::where('username', 'newfox')->firstOrFail();
    $f = Folder::where('user_id', $u->id)->orderBy('position')->get();
    expect($f->pluck('kind')->map->value->all())->toBe(['gallery', 'scraps'])
        ->and($f->pluck('position')->all())->toBe([0, 1]);
});

it('cannot delete or rename gallery/scraps', function () {
    $u = User::factory()->create();
    foreach (['gallery', 'scraps'] as $kind) {
        $f = folderOf($u, $kind);
        $this->actingAs($u, 'sanctum')->patchJson("/api/v1/folders/{$f->public_id}", ['name' => 'Renamed'])
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_state');
        $this->actingAs($u, 'sanctum')->deleteJson("/api/v1/folders/{$f->public_id}")
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_state');
    }
});

it('creates, renames and validates custom folders', function () {
    $u = User::factory()->create();
    $id = $this->actingAs($u, 'sanctum')->postJson('/api/v1/folders', ['name' => 'Commissions', 'description' => 'd'])
        ->assertCreated()->assertJsonPath('data.kind', 'custom')->assertJsonPath('data.name', 'Commissions')
        ->assertJsonPath('data.position', 2)->assertJsonPath('data.count', 0)->json('data.id');
    $this->postJson('/api/v1/folders', ['name' => 'commissions'])->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['fields' => ['name']]]);
    $this->postJson('/api/v1/folders', ['name' => 'Gallery'])->assertStatus(422);
    $this->postJson('/api/v1/folders', ['name' => ''])->assertStatus(422);
    $this->postJson('/api/v1/folders', ['name' => str_repeat('a', 61)])->assertStatus(422);
    $this->postJson('/api/v1/folders', ['name' => 'ok', 'description' => str_repeat('a', 501)])->assertStatus(422);
    $this->patchJson("/api/v1/folders/{$id}", ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.name', 'Renamed');
    $this->postJson('/api/v1/folders', ['name' => 'Commissions'])->assertCreated();
});

it('caps custom folders at 100 per user', function () {
    $u = User::factory()->create();
    for ($i = 0; $i < 100; $i++) {
        Folder::create(['user_id' => $u->id, 'name' => "f{$i}", 'kind' => 'custom', 'position' => $i + 2]);
    }
    $this->actingAs($u, 'sanctum')->postJson('/api/v1/folders', ['name' => 'one more'])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['name']]]);
});

it('requires authentication to mutate folders', function () {
    $this->postJson('/api/v1/folders', ['name' => 'x'])->assertUnauthorized();
});

it('deleting a custom folder keeps submissions in gallery', function () {
    $u = User::factory()->create();
    $s = Submission::factory()->for($u)->create();
    $scrap = Submission::factory()->for($u)->create();
    $fid = $this->actingAs($u, 'sanctum')->postJson('/api/v1/folders', ['name' => 'Temp'])->json('data.id');
    $this->patchJson("/api/v1/submissions/{$s->public_id}", ['folder' => $fid])->assertOk();
    $this->patchJson("/api/v1/submissions/{$scrap->public_id}", ['folder' => 'scraps'])->assertOk();
    $this->patchJson("/api/v1/submissions/{$scrap->public_id}", ['folder' => $fid])->assertOk();
    expect(folderIdsOf($s->fresh()))->toBe(['gallery', 'Temp'])
        ->and(folderIdsOf($scrap->fresh()))->toBe(['scraps', 'Temp']);

    $this->deleteJson("/api/v1/folders/{$fid}")->assertNoContent();
    expect(Folder::where('public_id', $fid)->exists())->toBeFalse()
        ->and(folderIdsOf($s->fresh()))->toBe(['gallery'])
        ->and(folderIdsOf($scrap->fresh()))->toBe(['scraps']);
});

it('deleting a custom folder moves orphaned submissions into gallery', function () {
    $u = User::factory()->create();
    $s = Submission::factory()->for($u)->create();
    $f = Folder::create(['user_id' => $u->id, 'name' => 'Only', 'kind' => 'custom', 'position' => 2]);
    DB::table('folder_submission')->where('submission_id', $s->id)->delete();
    DB::table('folder_submission')->insert(['folder_id' => $f->id, 'submission_id' => $s->id, 'position' => 0]);
    $this->actingAs($u, 'sanctum')->deleteJson("/api/v1/folders/{$f->public_id}")->assertNoContent();
    expect(folderIdsOf($s->fresh()))->toBe(['gallery']);
});

it('moving a submission from gallery to scraps removes it from gallery', function () {
    $u = User::factory()->create();
    $s = Submission::factory()->for($u)->create();
    expect(folderIdsOf($s))->toBe(['gallery']);
    $this->actingAs($u, 'sanctum')->patchJson("/api/v1/submissions/{$s->public_id}", ['folder' => 'scraps'])
        ->assertOk()->assertJsonPath('data.folders.0.kind', 'scraps')->assertJsonCount(1, 'data.folders');
    expect(folderIdsOf($s->fresh()))->toBe(['scraps']);
    $this->patchJson("/api/v1/submissions/{$s->public_id}", ['folder' => 'gallery'])->assertOk();
    expect(folderIdsOf($s->fresh()))->toBe(['gallery']);
});

it('new items are placed at the top of a folder', function () {
    $u = User::factory()->create();
    $a = Submission::factory()->for($u)->create();
    $b = Submission::factory()->for($u)->create();
    $g = folderOf($u, 'gallery');
    $rows = DB::table('folder_submission')->where('folder_id', $g->id)->orderBy('position')->get();
    expect($rows->pluck('submission_id')->all())->toBe([$b->id, $a->id])
        ->and($rows->pluck('position')->all())->toBe([-1, 0]);
    $this->actingAs($u, 'sanctum')->patchJson("/api/v1/submissions/{$a->public_id}", ['folder' => 'scraps'])->assertOk();
    $this->patchJson("/api/v1/submissions/{$a->public_id}", ['folder' => 'gallery'])->assertOk();
    expect(DB::table('folder_submission')->where('folder_id', $g->id)->orderBy('position')->pluck('submission_id')->all())
        ->toBe([$a->id, $b->id]);
});

it('reorder uses the owner-listed set and keeps unlisted items after it', function () {
    $u = User::factory()->create();
    $a = Submission::factory()->for($u)->create();
    $hidden = Submission::factory()->for($u)->create(['visibility' => 'unlisted']);
    $b = Submission::factory()->for($u)->create();
    $g = folderOf($u, 'gallery');
    $url = "/api/v1/folders/{$g->public_id}/order";

    $this->actingAs($u, 'sanctum')->putJson($url, ['submission_ids' => [$a->public_id, $b->public_id, $hidden->public_id]])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['submission_ids']]]);
    $this->putJson($url, ['submission_ids' => [$a->public_id, $a->public_id]])->assertStatus(422);
    $this->putJson($url, ['submission_ids' => [$a->public_id, $b->public_id]])->assertNoContent();
    expect(DB::table('folder_submission')->where('folder_id', $g->id)->orderBy('position')->pluck('submission_id')->all())
        ->toBe([$a->id, $b->id, $hidden->id]);
});

it('reorder requires exactly the folder\'s current set', function () {
    $u = User::factory()->create();
    $a = Submission::factory()->for($u)->create();
    $b = Submission::factory()->for($u)->create();
    $c = Submission::factory()->for($u)->create();
    $other = Submission::factory()->create();
    $g = folderOf($u, 'gallery');
    $url = "/api/v1/folders/{$g->public_id}/order";

    foreach ([
        [$a->public_id, $b->public_id],
        [$a->public_id, $b->public_id, $c->public_id, $other->public_id],
        [$a->public_id, $a->public_id, $b->public_id, $c->public_id],
        [$a->public_id, $b->public_id, 'zzzzzzzzzz'],
    ] as $bad) {
        $this->actingAs($u, 'sanctum')->putJson($url, ['submission_ids' => $bad])->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['fields' => ['submission_ids']]]);
    }
    $this->putJson($url, ['submission_ids' => [$c->public_id, $a->public_id, $b->public_id]])->assertNoContent();
    expect(DB::table('folder_submission')->where('folder_id', $g->id)->orderBy('position')->pluck('submission_id')->all())
        ->toBe([$c->id, $a->id, $b->id]);
});

it('cannot add a submission to another user\'s folder', function () {
    $u = User::factory()->create();
    $other = User::factory()->create();
    $theirs = Folder::create(['user_id' => $other->id, 'name' => 'Theirs', 'kind' => 'custom', 'position' => 2]);
    $s = Submission::factory()->for($u)->create();
    $this->actingAs($u, 'sanctum')->patchJson("/api/v1/submissions/{$s->public_id}", ['folder' => $theirs->public_id])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['folder']]]);
    $this->patchJson("/api/v1/submissions/{$s->public_id}", ['folder' => 'nonexistent'])->assertStatus(422);
    // Folders are only addressable by their owner for writes.
    $this->patchJson("/api/v1/folders/{$theirs->public_id}", ['name' => 'x'])->assertNotFound()->assertJsonPath('error.code', 'not_found');
    $this->deleteJson("/api/v1/folders/{$theirs->public_id}")->assertNotFound();
    $this->putJson("/api/v1/folders/{$theirs->public_id}/order", ['submission_ids' => []])->assertNotFound();
    expect(folderIdsOf($s->fresh()))->toBe(['gallery']);
});

it('creating a submission defaults to gallery and honors a custom folder', function () {
    $u = User::factory()->create();
    $fid = $this->actingAs($u, 'sanctum')->postJson('/api/v1/folders', ['name' => 'Cmn'])->json('data.id');
    $base = ['type' => 'writing', 'title' => 'T', 'body' => 'b', 'rating' => 'general', 'visibility' => 'public'];
    $this->postJson('/api/v1/submissions', $base)->assertCreated()
        ->assertJsonPath('data.folders.0.kind', 'gallery')->assertJsonCount(1, 'data.folders');
    $this->postJson('/api/v1/submissions', $base + ['folder' => 'scraps'])->assertCreated()
        ->assertJsonPath('data.folders.0.kind', 'scraps');
    $this->postJson('/api/v1/submissions', $base + ['folder' => $fid])->assertCreated()
        ->assertJsonCount(2, 'data.folders');
    $this->postJson('/api/v1/submissions', $base + ['folder' => 'bogus'])->assertStatus(422)
        ->assertJsonStructure(['error' => ['fields' => ['folder']]]);
});

it('backfill migration creates default folders and places existing non-trashed submissions in gallery', function () {
    $u = User::factory()->create();
    $a = Submission::factory()->for($u)->create();
    $gone = Submission::factory()->for($u)->create();
    $gone->delete();
    DB::table('folder_submission')->delete();
    DB::table('folders')->delete();

    $run = fn () => (include database_path('migrations/2026_11_06_150100_backfill_default_folders.php'))->up();
    $run();
    $run(); // idempotent

    expect(Folder::where('user_id', $u->id)->count())->toBe(2)
        ->and(folderIdsOf($a->fresh()))->toBe(['gallery'])
        ->and(DB::table('folder_submission')->count())->toBe(1);
});
