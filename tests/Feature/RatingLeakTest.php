<?php

require_once __DIR__.'/../Support/SeedsRatedContent.php';

const LEAK_EXPECTED = [
    'guest' => ['general'],
    'unconfirmed-forced-adult' => ['general'],
    'confirmed-general' => ['general'],
    'confirmed-mature' => ['general', 'mature'],
    'confirmed-adult' => ['general', 'mature', 'adult'],
];

dataset('leak viewers', array_keys(LEAK_EXPECTED));

dataset('leak list endpoints', [
    'browse' => '/api/v1/browse',
    'search q' => '/api/v1/search?q=leak',
    'search tags' => '/api/v1/search?tags=leak',
    'tag page' => '/api/v1/tags/leak',
    'gallery' => '/api/v1/users/artist/submissions',
    'custom folder' => '/api/v1/users/artist/submissions?folder={custom}',
]);

/** Switch the acting viewer (null = guest) and return the seeded content. */
function leakAs(string $viewer): array
{
    $seed = seedRatedContent();
    $user = leakViewer($viewer);
    app('auth')->forgetGuards();
    if ($user) {
        test()->actingAs($user, 'sanctum');
    }

    return $seed;
}

function leakRatings(array $ids, array $seed): array
{
    $byId = collect($seed['subs'])->mapWithKeys(fn ($s, $name) => [$s->public_id => $name]);
    $names = collect($ids)->map(fn ($id) => $byId[$id] ?? "UNKNOWN:$id")->all();
    sort($names);

    return $names;
}

function leakSorted(array $a): array
{
    sort($a);

    return $a;
}

it('lists only permitted ratings', function (string $viewer, string $url) {
    $seed = leakAs($viewer);
    $url = str_replace('{custom}', $seed['custom']->public_id, $url);
    $res = test()->getJson($url)->assertOk();

    expect(leakRatings(collect($res->json('data'))->pluck('id')->all(), $seed))->toBe(leakSorted(LEAK_EXPECTED[$viewer]));
    leakAssertNoHiddenLeak($viewer, $seed, $res->getContent());
})->with('leak viewers')->with('leak list endpoints');

it('submission lookup 200s only for permitted ratings', function (string $viewer) {
    $seed = leakAs($viewer);
    foreach ($seed['subs'] as $name => $s) {
        $res = test()->getJson("/api/v1/submissions/{$s->public_id}");
        if (in_array($name, LEAK_EXPECTED[$viewer], true)) {
            $res->assertOk();
            if ($name === 'adult') {
                // Adult media is served as signed, temporary URLs.
                expect($res->json('data.media'))->not->toBeEmpty();
                foreach ($res->json('data.media') as $m) {
                    expect($m['url'])->toStartWith('fake://temp/private/media/')->toContain('expires=');
                }
            }
        } else {
            $res->assertNotFound();
        }
        leakAssertNoHiddenLeak($viewer, $seed, $res->getContent());
    }
})->with('leak viewers');

it('folder counts equal the visible seeded items', function (string $viewer) {
    $seed = leakAs($viewer);
    $res = test()->getJson('/api/v1/users/artist/folders')->assertOk();
    $expected = count(LEAK_EXPECTED[$viewer]);

    $byKind = collect($res->json('data'))->keyBy('kind');
    expect($byKind['gallery']['count'])->toBe($expected)
        ->and($byKind['scraps']['count'])->toBe(0)
        ->and($byKind['custom']['count'])->toBe($expected);
    leakAssertNoHiddenLeak($viewer, $seed, $res->getContent());
})->with('leak viewers');

it('profile pins show only permitted ratings', function (string $viewer) {
    $seed = leakAs($viewer);
    $res = test()->getJson('/api/v1/users/artist')->assertOk();

    expect(leakRatings(collect($res->json('profile.pinned'))->pluck('id')->all(), $seed))->toBe(leakSorted(LEAK_EXPECTED[$viewer]));
    leakAssertNoHiddenLeak($viewer, $seed, $res->getContent());
})->with('leak viewers');

it('tag autocomplete suggests only tags and counts from permitted ratings', function (string $viewer) {
    $seed = leakAs($viewer);
    $res = test()->getJson('/api/v1/tags/autocomplete?q=leak')->assertOk();

    $allowed = LEAK_EXPECTED[$viewer];
    $expected = [['slug' => 'leak', 'usage_count' => count($allowed)]];
    foreach (['adult', 'mature'] as $name) {
        if (in_array($name, $allowed, true)) {
            $expected[] = ['slug' => 'leak_'.$name, 'usage_count' => 1];
        }
    }
    $res->assertExactJson(['data' => $expected]);
    leakAssertNoHiddenLeak($viewer, $seed, $res->getContent());
})->with('leak viewers');

it('tag page header counts only permitted ratings and hides tags used only above them', function (string $viewer) {
    $seed = leakAs($viewer);
    $allowed = LEAK_EXPECTED[$viewer];

    $res = test()->getJson('/api/v1/tags/leak')->assertOk()
        ->assertJsonPath('tag.slug', 'leak')
        ->assertJsonPath('tag.usage_count', count($allowed));
    leakAssertNoHiddenLeak($viewer, $seed, $res->getContent());

    foreach (['mature', 'adult'] as $name) {
        $res = test()->getJson('/api/v1/tags/leak_'.$name);
        if (in_array($name, $allowed, true)) {
            $res->assertOk()->assertJsonPath('tag.usage_count', 1)->assertJsonCount(1, 'data');
        } else {
            $res->assertNotFound()->assertJsonPath('error.code', 'not_found');
        }
        leakAssertNoHiddenLeak($viewer, $seed, $res->getContent());
    }
})->with('leak viewers');

/** No viewer may see the id, asset paths or own tag of a submission rated above what they may view. */
function leakAssertNoHiddenLeak(string $viewer, array $seed, string $body): void
{
    foreach (['mature', 'adult'] as $name) {
        if (in_array($name, LEAK_EXPECTED[$viewer], true)) {
            continue;
        }
        expect($body)->not->toContain($seed['subs'][$name]->public_id)
            ->not->toContain('leak_'.$name);
        foreach ($seed['paths'][$name] as $p) {
            expect($body)->not->toContain($p);
        }
    }
}
