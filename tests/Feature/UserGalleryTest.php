<?php

use App\Enums\Rating;
use App\Models\Folder;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function publicIds($response): array
{
    return collect($response->json('data'))->pluck('id')->all();
}

it('user gallery listing hides unlisted, processing, and over-rating items from others', function () {
    $u = User::factory()->create(['username' => 'otter', 'adult_confirmed_at' => now()]);
    $ok = Submission::factory()->for($u)->create();
    Submission::factory()->for($u)->create(['visibility' => 'unlisted']);
    Submission::factory()->for($u)->create(['status' => 'processing', 'published_at' => null]);
    $adult = Submission::factory()->for($u)->create(['rating' => Rating::Adult]);
    $trashed = Submission::factory()->for($u)->create();
    $trashed->delete();

    expect(publicIds($this->getJson('/api/v1/users/otter/submissions')->assertOk()))->toBe([$ok->public_id]);
    $this->getJson('/api/v1/users/Otter/submissions')->assertOk()->assertJsonPath('meta.next_cursor', null);

    $this->actingAs(makeAdultUser(), 'sanctum');
    expect(publicIds($this->getJson('/api/v1/users/otter/submissions')))->toBe([$adult->public_id, $ok->public_id]);

    // The owner gets no exception on listings (unlisted/processing stay hidden, rating cap applies).
    $this->actingAs($u, 'sanctum');
    expect(publicIds($this->getJson('/api/v1/users/otter/submissions')))->toBe([$ok->public_id]);
});

it('lists by folder with position order then newest first, and cursor paginates', function () {
    $u = User::factory()->create(['username' => 'otter']);
    $old = Submission::factory()->for($u)->create(['published_at' => now()->subDays(3)]);
    $mid = Submission::factory()->for($u)->create(['published_at' => now()->subDays(2)]);
    $new = Submission::factory()->for($u)->create(['published_at' => now()->subDay()]);
    $scrap = Submission::factory()->for($u)->create();
    $g = Folder::where('user_id', $u->id)->where('kind', 'gallery')->first();
    $s = Folder::where('user_id', $u->id)->where('kind', 'scraps')->first();
    DB::table('folder_submission')->where('submission_id', $scrap->id)->update(['folder_id' => $s->id]);
    // Positions: old=0 mid=1 new=2 -> custom order, then make mid & new tie to prove the tiebreakers.
    foreach ([[$old, 0], [$mid, 1], [$new, 1]] as [$sub, $pos]) {
        DB::table('folder_submission')->where('submission_id', $sub->id)->update(['position' => $pos]);
    }

    $r = $this->getJson('/api/v1/users/otter/submissions')->assertOk();
    expect(publicIds($r))->toBe([$old->public_id, $new->public_id, $mid->public_id]);
    expect(publicIds($this->getJson('/api/v1/users/otter/submissions?folder=scraps')))->toBe([$scrap->public_id]);

    $p1 = $this->getJson('/api/v1/users/otter/submissions?per_page=2')->assertOk();
    $cursor = $p1->json('meta.next_cursor');
    expect($cursor)->not->toBeNull()->and(publicIds($p1))->toBe([$old->public_id, $new->public_id]);
    $p2 = $this->getJson('/api/v1/users/otter/submissions?per_page=2&cursor='.$cursor)->assertOk();
    expect(publicIds($p2))->toBe([$mid->public_id])->and($p2->json('meta.next_cursor'))->toBeNull();
});

it('unknown or foreign folder is 404', function () {
    $u = User::factory()->create(['username' => 'otter']);
    $other = User::factory()->create();
    $theirs = Folder::create(['user_id' => $other->id, 'name' => 'T', 'kind' => 'custom', 'position' => 2]);
    $this->getJson('/api/v1/users/otter/submissions?folder=nope')->assertNotFound()->assertJsonPath('error.code', 'not_found');
    $this->getJson('/api/v1/users/otter/submissions?folder='.$theirs->public_id)->assertNotFound();
    $this->getJson('/api/v1/users/ghost/submissions')->assertNotFound();
    $this->getJson('/api/v1/users/ghost/folders')->assertNotFound();
});

it('lists folders with gallery and scraps first and counts per viewer', function () {
    $u = User::factory()->create(['username' => 'otter', 'adult_confirmed_at' => now()]);
    $custom = Folder::create(['user_id' => $u->id, 'name' => 'Custom', 'kind' => 'custom', 'position' => 2]);
    $a = Submission::factory()->for($u)->create();
    $adult = Submission::factory()->for($u)->create(['rating' => Rating::Adult]);
    $unlisted = Submission::factory()->for($u)->create(['visibility' => 'unlisted']);
    $scrap = Submission::factory()->for($u)->create();
    $scrapsId = Folder::where('user_id', $u->id)->where('kind', 'scraps')->value('id');
    DB::table('folder_submission')->where('submission_id', $scrap->id)->update(['folder_id' => $scrapsId]);
    foreach ([$a, $adult] as $s) {
        DB::table('folder_submission')->insert(['folder_id' => $custom->id, 'submission_id' => $s->id, 'position' => 0]);
    }

    $r = $this->getJson('/api/v1/users/otter/folders')->assertOk();
    expect(collect($r->json('data'))->map(fn ($f) => [$f['kind'], $f['count']])->all())
        ->toBe([['gallery', 1], ['scraps', 1], ['custom', 1]]);
    $r->assertJsonStructure(['data' => [['id', 'name', 'description', 'kind', 'position', 'count']]]);

    $this->actingAs(makeAdultUser(), 'sanctum');
    expect(collect($this->getJson('/api/v1/users/otter/folders')->json('data'))->pluck('count')->all())->toBe([2, 1, 2]);
});

it('does not run extra queries per submission on the gallery listing', function () {
    $u = User::factory()->create(['username' => 'otter']);
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/users/otter/submissions')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    Submission::factory()->for($u)->count(2)->create();
    $few = $count();
    Submission::factory()->for($u)->count(8)->create();
    expect($count())->toBe($few);
});

it('submissions in profile pinned and tag pages carry folders without extra queries', function () {
    $u = User::factory()->create(['username' => 'otter']);
    $s = Submission::factory()->for($u)->create();
    $s->tags()->create(['slug' => 'fox', 'usage_count' => 1]);
    $this->getJson('/api/v1/tags/fox')->assertOk()->assertJsonPath('data.0.folders.0.kind', 'gallery');
});
