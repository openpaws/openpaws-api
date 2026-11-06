<?php

use App\Enums\Rating;
use App\Models\Submission;
use App\Models\Tag;
use App\Models\User;
use App\Search\TagAwareCollectionEngine;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Scout\EngineManager;

function searchIds($response): array
{
    return collect($response->json('data'))->pluck('id')->all();
}

function tagSub(array $tags, array $attrs = [], ?User $owner = null): Submission
{
    $attrs += ['title' => 'Fox picture'];
    $s = Submission::factory()->for($owner ?? User::factory()->create())->create($attrs);
    $s->tags()->sync(collect($tags)->map(fn ($t) => Tag::firstOrCreate(['slug' => $t])->id)->all());
    $s->unsetRelation('tags')->searchable();

    return $s;
}

it('search filters by rating for guests (no mature/adult)', function () {
    $owner = User::factory()->create(['adult_confirmed_at' => now()]);
    $g = tagSub([], ['title' => 'fox general'], $owner);
    $m = tagSub([], ['title' => 'fox mature', 'rating' => Rating::Mature], $owner);
    $a = tagSub([], ['title' => 'fox adult', 'rating' => Rating::Adult], $owner);

    expect(searchIds($this->getJson('/api/v1/search?q=fox')->assertOk()))->toBe([$g->public_id]);

    // A confirmed viewer opted into mature sees mature but not adult.
    $viewer = User::factory()->create(['adult_confirmed_at' => now(), 'max_rating' => 'mature']);
    $this->actingAs($viewer, 'sanctum');
    expect(searchIds($this->getJson('/api/v1/search?q=fox')))->toEqualCanonicalizing([$g->public_id, $m->public_id]);
});

it('search supports required and excluded tags', function () {
    $both = tagSub(['wolf', 'red'], ['title' => 'a']);
    $wolf = tagSub(['wolf'], ['title' => 'b']);
    $red = tagSub(['red'], ['title' => 'c']);

    expect(searchIds($this->getJson('/api/v1/search?tags=wolf,red')))->toBe([$both->public_id]);
    expect(searchIds($this->getJson('/api/v1/search?tags=Wolf&exclude_tags=red')))->toBe([$wolf->public_id]);
    expect(searchIds($this->getJson('/api/v1/search?exclude_tags=wolf')))->toBe([$red->public_id]);
    $this->getJson('/api/v1/search?tags='.implode(',', range(1, 21)))->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    $this->getJson('/api/v1/search?q='.str_repeat('a', 201))->assertStatus(422);
    $this->getJson('/api/v1/search?type=bogus')->assertStatus(422);
});

it('search excludes unlisted and processing', function () {
    $ok = tagSub([], ['title' => 'fox ok']);
    tagSub([], ['title' => 'fox unlisted', 'visibility' => 'unlisted']);
    tagSub([], ['title' => 'fox processing', 'status' => 'processing', 'published_at' => null]);
    $trashed = tagSub([], ['title' => 'fox trashed']);
    $trashed->delete();

    expect(searchIds($this->getJson('/api/v1/search?q=fox')))->toBe([$ok->public_id]);
});

it('search throttles at 60/min', function () {
    RateLimiter::clear('search');
    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/api/v1/search?q=x')->assertOk();
    }
    $this->getJson('/api/v1/search?q=x')->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
});

it('search paginates with an opaque cursor and rejects invalid cursors', function () {
    foreach (range(1, 3) as $i) {
        tagSub([], ['title' => "fox $i"]);
    }
    $first = $this->getJson('/api/v1/search?q=fox&per_page=2&sort=new')->assertOk();
    expect($first->json('data'))->toHaveCount(2);
    $cursor = $first->json('meta.next_cursor');
    expect($cursor)->toBeString();

    $second = $this->getJson('/api/v1/search?q=fox&per_page=2&sort=new&cursor='.$cursor)->assertOk();
    expect($second->json('data'))->toHaveCount(1)->and($second->json('meta.next_cursor'))->toBeNull();
    expect(array_intersect(searchIds($first), searchIds($second)))->toBe([]);

    foreach (['garbage!!', rtrim(strtr(base64_encode('{"p":0}'), '+/', '-_'), '='), rtrim(strtr(base64_encode('nope'), '+/', '-_'), '=')] as $bad) {
        $this->getJson('/api/v1/search?q=fox&cursor='.$bad)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['fields' => ['cursor']]]);
    }
});

it('refreshes the index after a tag change and removes soft-deleted items', function () {
    $recorder = new class extends TagAwareCollectionEngine
    {
        public array $updates = [];

        public array $deletes = [];

        public function update($models)
        {
            foreach ($models as $m) {
                $this->updates[$m->id] = $m->toSearchableArray();
            }
        }

        public function delete($models)
        {
            foreach ($models as $m) {
                $this->deletes[] = $m->id;
            }
        }
    };
    app(EngineManager::class)->extend('collection', fn () => $recorder);
    app(EngineManager::class)->forgetEngines();

    $owner = User::factory()->create(['email_verified_at' => now()]);
    $s = Submission::factory()->for($owner)->create();

    $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/submissions/{$s->public_id}", ['tags' => ['Brand New']])->assertOk();
    expect($recorder->updates[$s->id]['tags'])->toBe(['brand_new']);
    expect(searchIds($this->getJson('/api/v1/search?tags=brand_new')))->toBe([$s->public_id]);

    $this->deleteJson("/api/v1/submissions/{$s->public_id}")->assertNoContent();
    expect($recorder->deletes)->toContain($s->id);
    expect(searchIds($this->getJson('/api/v1/search?tags=brand_new')))->toBe([]);

    app(EngineManager::class)->forgetEngines();
});
