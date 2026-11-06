<?php

use App\Enums\Rating;
use App\Models\Submission;
use App\Models\User;

it('shows a published general submission to guests', function () {
    $s = Submission::factory()->create(['description' => '_x_']);
    $this->getJson("/api/v1/submissions/{$s->public_id}")->assertOk()
        ->assertJsonPath('data.id', $s->public_id)
        ->assertJsonPath('data.description_html', '<p><em>x</em></p>')
        ->assertJsonPath('data.user.username', $s->user->username)
        ->assertJsonMissingPath('data.user.email')
        ->assertJsonMissingPath('data.body');
});

it('owner can view own processing submission; others get 404', function (string $status) {
    $owner = User::factory()->create();
    $s = Submission::factory()->for($owner)->create(['status' => $status, 'published_at' => null]);

    $this->actingAs($owner, 'sanctum')->getJson("/api/v1/submissions/{$s->public_id}")->assertOk()->assertJsonPath('data.status', $status);
    $this->actingAs(makeAdultUser(), 'sanctum')->getJson("/api/v1/submissions/{$s->public_id}")
        ->assertNotFound()->assertJsonPath('error.code', 'not_found');
    app('auth')->forgetGuards();
    $this->getJson("/api/v1/submissions/{$s->public_id}")->assertNotFound();
})->with(['processing', 'failed', 'hidden', 'removed']);

it('published status without published_at is not visible to others', function () {
    $s = Submission::factory()->create(['published_at' => null]);
    $this->getJson("/api/v1/submissions/{$s->public_id}")->assertNotFound();
});

it('guest gets 404 for mature and adult published submissions', function (Rating $rating) {
    $s = Submission::factory()->create(['rating' => $rating]);
    $this->getJson("/api/v1/submissions/{$s->public_id}")->assertNotFound()->assertJsonPath('error.code', 'not_found');
})->with([Rating::Mature, Rating::Adult]);

it('logged-in users without adult confirmation see only general', function (Rating $rating) {
    $u = User::factory()->create(['max_rating' => 'adult']); // not confirmed: effective is general
    $s = Submission::factory()->create(['rating' => $rating]);
    $this->actingAs($u, 'sanctum')->getJson("/api/v1/submissions/{$s->public_id}")->assertNotFound();
})->with([Rating::Mature, Rating::Adult]);

it('confirmed adult user with max_rating mature sees mature but 404s adult', function () {
    $u = makeAdultUser('mature');
    $mature = Submission::factory()->create(['rating' => Rating::Mature]);
    $adult = Submission::factory()->create(['rating' => Rating::Adult]);
    $general = Submission::factory()->create();

    $this->actingAs($u, 'sanctum')->getJson("/api/v1/submissions/{$general->public_id}")->assertOk();
    $this->actingAs($u, 'sanctum')->getJson("/api/v1/submissions/{$mature->public_id}")->assertOk();
    $this->actingAs($u, 'sanctum')->getJson("/api/v1/submissions/{$adult->public_id}")->assertNotFound();
    $this->actingAs(makeAdultUser('adult'), 'sanctum')->getJson("/api/v1/submissions/{$adult->public_id}")->assertOk();
});

it('owner sees their own adult submission even with a general max rating', function () {
    $owner = User::factory()->create(['adult_confirmed_at' => now()]);
    $s = Submission::factory()->for($owner)->create(['rating' => Rating::Adult]);
    $this->actingAs($owner, 'sanctum')->getJson("/api/v1/submissions/{$s->public_id}")->assertOk();
});

it('unlisted submission is viewable by link', function () {
    $s = Submission::factory()->create(['visibility' => 'unlisted']);
    $this->getJson("/api/v1/submissions/{$s->public_id}")->assertOk()->assertJsonPath('data.visibility', 'unlisted');
});

it('returns body and body_html for writing on show only', function () {
    $s = Submission::factory()->create(['type' => 'writing', 'body' => "# Title\n\n<script>x</script>"]);
    $r = $this->getJson("/api/v1/submissions/{$s->public_id}")->assertOk()->assertJsonPath('data.body', "# Title\n\n<script>x</script>");
    expect($r->json('data.body_html'))->toContain('<h1>Title</h1>')->not->toContain('<script');

    $img = Submission::factory()->create();
    $this->getJson("/api/v1/submissions/{$img->public_id}")->assertOk()->assertJsonMissingPath('data.body')->assertJsonMissingPath('data.body_html');
});

it('404s for unknown ids', function () {
    $this->getJson('/api/v1/submissions/zzzzzzzzzz')->assertNotFound()->assertJsonPath('error.code', 'not_found');
});

it('visibleTo and listedFor scopes apply the rating, status and visibility rules', function () {
    $owner = User::factory()->create(['adult_confirmed_at' => now()]);
    $pubGeneral = Submission::factory()->for($owner)->create();
    $pubAdult = Submission::factory()->for($owner)->create(['rating' => Rating::Adult]);
    $unlisted = Submission::factory()->for($owner)->create(['visibility' => 'unlisted']);
    $processing = Submission::factory()->for($owner)->create(['status' => 'processing', 'published_at' => null]);
    $trashed = Submission::factory()->for($owner)->create();
    $trashed->delete();

    $ids = fn ($q) => $q->pluck('id')->sort()->values()->all();
    $sorted = fn (...$s) => collect($s)->pluck('id')->sort()->values()->all();

    expect($ids(Submission::visibleTo($owner)))->toBe($sorted($pubGeneral, $pubAdult, $unlisted, $processing));
    expect($ids(Submission::visibleTo(null)))->toBe($sorted($pubGeneral, $unlisted));
    expect($ids(Submission::visibleTo(makeAdultUser())))->toBe($sorted($pubGeneral, $pubAdult, $unlisted));

    expect($ids(Submission::listedFor($owner)))->toBe($sorted($pubGeneral)); // no owner exception
    expect($ids(Submission::listedFor(null)))->toBe($sorted($pubGeneral));
    expect($ids(Submission::listedFor(makeAdultUser())))->toBe($sorted($pubGeneral, $pubAdult));
});
