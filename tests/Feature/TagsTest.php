<?php

use App\Enums\Rating;
use App\Enums\SubmissionStatus;
use App\Enums\Visibility;
use App\Http\Controllers\Api\V1\SubmissionController;
use App\Jobs\ProcessSubmission;
use App\Models\Submission;
use App\Models\Tag;
use App\Models\TagAlias;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

function tagged(User $owner, array $tags, array $attrs = []): Submission
{
    $s = Submission::factory()->for($owner)->create($attrs);
    $ids = collect($tags)->map(fn ($t) => Tag::firstOrCreate(['slug' => $t])->id)->all();
    $s->tags()->sync($ids);
    Tag::recount($ids);

    return $s;
}

function verified(): User
{
    return User::factory()->create(['email_verified_at' => now()]);
}

it('persists normalised tags on PATCH and returns slugs', function () {
    $owner = verified();
    $s = Submission::factory()->for($owner)->create();

    $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/submissions/{$s->public_id}", [
        'tags' => ['Red Fox', ' red fox ', 'wolf!', '!!!'],
    ])->assertOk()->assertJsonPath('data.tags', ['red_fox', 'wolf']);

    expect(Tag::pluck('usage_count', 'slug')->all())->toBe(['red_fox' => 1, 'wolf' => 1]);

    $this->patchJson("/api/v1/submissions/{$s->public_id}", ['tags' => ['wolf']])->assertOk()
        ->assertJsonPath('data.tags', ['wolf']);
    expect(Tag::pluck('usage_count', 'slug')->all())->toBe(['red_fox' => 0, 'wolf' => 1]);
});

it('more than 60 distinct tags is 422', function () {
    $owner = verified();
    $s = Submission::factory()->for($owner)->create();

    $tags = array_map(fn ($i) => "t$i", range(1, 61));
    $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/submissions/{$s->public_id}", ['tags' => $tags])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['fields' => ['tags']]]);

    // 100 raw items that collapse to few distinct tags are fine.
    $dupes = array_map(fn ($i) => $i % 2 ? 'Wolf' : 'wolf!', range(1, 100));
    $this->patchJson("/api/v1/submissions/{$s->public_id}", ['tags' => $dupes])->assertOk()
        ->assertJsonPath('data.tags', ['wolf']);

    // Exactly 60 is allowed.
    $this->patchJson("/api/v1/submissions/{$s->public_id}", ['tags' => array_slice($tags, 0, 60)])->assertOk();
});

it('persists tags on store and counts them only once published', function () {
    Queue::fake();
    $owner = verified();
    $session = makeUploadSession($owner, 'image');
    $this->actingAs($owner, 'sanctum')->postJson('/api/v1/submissions', [
        'type' => 'image', 'upload_session_id' => $session->id, 'title' => 'T', 'rating' => 'general',
        'visibility' => 'public', 'tags' => ['Fox', 'fox', 'Den Mother'],
    ])->assertCreated()->assertJsonPath('data.tags', ['den_mother', 'fox']);

    expect(Submission::first()->tags()->count())->toBe(2)
        ->and(Tag::sum('usage_count'))->toBe(0);
});

it('usage_count follows publish, visibility, rating and delete', function () {
    $owner = verified();
    $s = tagged($owner, ['fox'], ['status' => SubmissionStatus::Processing, 'published_at' => null]);
    $tag = Tag::where('slug', 'fox')->first();
    expect($tag->usage_count)->toBe(0);

    $s->forceFill(['status' => SubmissionStatus::Published, 'published_at' => now()])->save();
    Tag::recount([$tag->id]);
    expect($tag->fresh()->usage_count)->toBe(1);

    $url = "/api/v1/submissions/{$s->public_id}";
    $this->actingAs($owner, 'sanctum')->patchJson($url, ['visibility' => 'unlisted'])->assertOk();
    expect($tag->fresh()->usage_count)->toBe(0);
    $this->patchJson($url, ['visibility' => 'public'])->assertOk();
    expect($tag->fresh()->usage_count)->toBe(1);

    $this->deleteJson($url)->assertNoContent();
    expect($tag->fresh()->usage_count)->toBe(0);
});

it('ProcessSubmission publishing recounts tags', function () {
    $owner = verified();
    $s = Submission::factory()->for($owner)->create([
        'type' => 'writing', 'body' => "# hi\n\ntext", 'status' => SubmissionStatus::Processing, 'published_at' => null,
    ]);
    $tag = Tag::create(['slug' => 'fox']);
    $s->tags()->sync([$tag->id]);

    ProcessSubmission::dispatchSync($s);

    expect($s->fresh()->status)->toBe(SubmissionStatus::Published)->and($tag->fresh()->usage_count)->toBe(1);
});

it('autocomplete orders by usage_count then slug and only lists used tags', function () {
    $o = User::factory()->create();
    tagged($o, ['wolf', 'wolfpack']);
    tagged($o, ['wolfpack']);
    tagged($o, ['wolfpack', 'wolf']);
    tagged($o, ['wolfy']);
    Tag::create(['slug' => 'wolfless']); // usage 0
    tagged($o, ['fox']);

    $this->getJson('/api/v1/tags/autocomplete?q=WOL')->assertOk()->assertExactJson(['data' => [
        ['slug' => 'wolfpack', 'usage_count' => 3],
        ['slug' => 'wolf', 'usage_count' => 2],
        ['slug' => 'wolfy', 'usage_count' => 1],
    ]]);
    $this->getJson('/api/v1/tags/autocomplete?q=!!!')->assertOk()->assertExactJson(['data' => []]);
    $this->getJson('/api/v1/tags/autocomplete')->assertOk()->assertExactJson(['data' => []]);
});

it('autocomplete returns at most 10', function () {
    $o = User::factory()->create();
    foreach (range(1, 12) as $i) {
        tagged($o, [sprintf('ab%02d', $i)]);
    }
    expect($this->getJson('/api/v1/tags/autocomplete?q=ab')->json('data'))->toHaveCount(10);
});

it('tag page lists only submissions listed for the viewer', function () {
    $o = User::factory()->create();
    $ok = tagged($o, ['fox']);
    tagged($o, ['fox'], ['visibility' => Visibility::Unlisted]);
    tagged($o, ['fox'], ['rating' => Rating::Adult]);
    tagged($o, ['fox'], ['status' => SubmissionStatus::Processing, 'published_at' => null]);
    tagged($o, ['other']);

    $this->getJson('/api/v1/tags/fox')->assertOk()
        ->assertJsonPath('tag.slug', 'fox')
        ->assertJsonPath('meta.next_cursor', null)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ok->public_id)
        ->assertJsonPath('data.0.tags', ['fox']);

    $adult = User::factory()->create(['max_rating' => 'adult', 'adult_confirmed_at' => now()]);
    expect($adult->effectiveMaxRating())->toBe(Rating::Adult);
    $this->actingAs($adult, 'sanctum')->getJson('/api/v1/tags/fox')->assertJsonCount(2, 'data');
});

it('tag page paginates with cursors, newest first', function () {
    $o = User::factory()->create();
    foreach (range(1, 3) as $i) {
        tagged($o, ['fox'], ['published_at' => now()->subMinutes(10 - $i)]);
    }
    $first = $this->getJson('/api/v1/tags/fox?per_page=2')->assertOk()->assertJsonCount(2, 'data');
    $cursor = $first->json('meta.next_cursor');
    expect($cursor)->not->toBeNull();
    $second = $this->getJson('/api/v1/tags/fox?per_page=2&cursor='.$cursor)->assertOk()->assertJsonCount(1, 'data');
    expect($second->json('meta.next_cursor'))->toBeNull();
});

it('tag page resolves aliases and 404s unknown tags', function () {
    $o = User::factory()->create();
    tagged($o, ['canine']);
    TagAlias::create(['alias_slug' => 'doggo', 'tag_id' => Tag::where('slug', 'canine')->value('id')]);

    $this->getJson('/api/v1/tags/doggo')->assertOk()->assertJsonPath('tag.slug', 'canine')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/tags/nope')->assertNotFound()->assertJsonPath('error.code', 'not_found');
});

it('alias on submission input resolves to the target tag', function () {
    $owner = verified();
    $tag = Tag::create(['slug' => 'canine']);
    TagAlias::create(['alias_slug' => 'doggo', 'tag_id' => $tag->id]);
    $s = Submission::factory()->for($owner)->create();

    $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/submissions/{$s->public_id}", ['tags' => ['Doggo', 'canine']])
        ->assertOk()->assertJsonPath('data.tags', ['canine']);
    expect(Tag::count())->toBe(1);
});

it('tag creation survives a concurrent creator inserting the same slug', function () {
    $owner = verified();
    $s = Submission::factory()->for($owner)->create();
    $raced = false;
    DB::beforeExecuting(function (string $query) use (&$raced) {
        if (! $raced && preg_match('/^insert (ignore )?into [`"]?tags[`"]?/i', $query)) {
            $raced = true;
            DB::table('tags')->insert(['slug' => 'fox', 'usage_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }
    });

    $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/submissions/{$s->public_id}", ['tags' => ['fox', 'wolf']])
        ->assertOk()->assertJsonPath('data.tags', ['fox', 'wolf']);

    expect($raced)->toBeTrue()
        ->and(Tag::where('slug', 'fox')->count())->toBe(1)
        ->and(Tag::count())->toBe(2)
        ->and(Tag::where('slug', 'fox')->value('usage_count'))->toBe(1);
});

it('tag id resolution throws instead of returning id 0 when a slug cannot be found', function () {
    $active = true;
    DB::beforeExecuting(function (string $query) use (&$active) {
        if ($active && preg_match('/^select .* from [`"]?tags[`"]? where .*for update/is', $query)) {
            DB::table('tags')->where('slug', 'ghost')->delete();
        }
    });

    try {
        resolveTagIdsFor(['ghost']);
    } finally {
        $active = false;
    }
})->throws(RuntimeException::class, 'ghost');

function resolveTagIdsFor(array $slugs): array
{
    $method = new ReflectionMethod(SubmissionController::class, 'resolveTagIds');

    return DB::transaction(fn () => $method->invoke(new SubmissionController, $slugs));
}

it('tag id resolution returns ids in the input order regardless of lock order', function () {
    $ab = resolveTagIdsFor(['wolf', 'fox']);
    $ba = resolveTagIdsFor(['fox', 'wolf']);

    $ids = Tag::pluck('id', 'slug');
    expect($ab)->toBe([$ids['wolf'], $ids['fox']])
        ->and($ba)->toBe([$ids['fox'], $ids['wolf']])
        ->and(Tag::count())->toBe(2);
});
