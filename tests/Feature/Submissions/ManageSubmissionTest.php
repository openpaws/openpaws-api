<?php

use App\Enums\Rating;
use App\Models\Submission;
use App\Models\User;

it('owner can PATCH title, description, rating and visibility', function () {
    $owner = User::factory()->create(['adult_confirmed_at' => now()]);
    $s = Submission::factory()->for($owner)->create();

    $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/submissions/{$s->public_id}", [
        'title' => 'New', 'description' => '*d*', 'rating' => 'adult', 'visibility' => 'unlisted',
        'tags' => ['a', 'b'], 'folder' => 'scraps',
    ])->assertOk()
        ->assertJsonPath('data.title', 'New')
        ->assertJsonPath('data.description_html', '<p><em>d</em></p>')
        ->assertJsonPath('data.rating', 'adult')
        ->assertJsonPath('data.visibility', 'unlisted')
        ->assertJsonPath('data.tags', ['a', 'b']);

    $s->refresh();
    expect($s->title)->toBe('New')->and($s->rating)->toBe(Rating::Adult);
});

it('only the owner can PATCH and DELETE', function () {
    $owner = User::factory()->create(['adult_confirmed_at' => now()]);
    $visible = Submission::factory()->for($owner)->create();
    $hidden = Submission::factory()->for($owner)->create(['status' => 'processing', 'published_at' => null]);
    $adult = Submission::factory()->for($owner)->create(['rating' => Rating::Adult]);
    $other = User::factory()->create();

    foreach ([$hidden, $adult] as $s) {
        $this->actingAs($other, 'sanctum')->patchJson("/api/v1/submissions/{$s->public_id}", ['title' => 'x'])
            ->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->actingAs($other, 'sanctum')->deleteJson("/api/v1/submissions/{$s->public_id}")->assertNotFound();
    }
    $this->actingAs($other, 'sanctum')->patchJson("/api/v1/submissions/{$visible->public_id}", ['title' => 'x'])
        ->assertForbidden()->assertJsonPath('error.code', 'forbidden');
    $this->actingAs($other, 'sanctum')->deleteJson("/api/v1/submissions/{$visible->public_id}")->assertForbidden();

    expect($visible->fresh()->title)->not->toBe('x')->and(Submission::count())->toBe(3);

    app('auth')->forgetGuards();
    $this->patchJson("/api/v1/submissions/{$visible->public_id}", ['title' => 'x'])->assertUnauthorized();
    $this->deleteJson("/api/v1/submissions/{$visible->public_id}")->assertUnauthorized();
});

it('body can only be edited on writing submissions', function () {
    $owner = User::factory()->create();
    $img = Submission::factory()->for($owner)->create();
    $w = Submission::factory()->for($owner)->create(['type' => 'writing', 'body' => 'old']);

    $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/submissions/{$img->public_id}", ['body' => 'x'])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['body']]]);
    $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/submissions/{$w->public_id}", ['body' => 'new'])->assertOk();
    expect($w->fresh()->body)->toBe('new');
});

it('validates PATCH fields', function (array $payload, string $field) {
    $owner = User::factory()->create();
    $s = Submission::factory()->for($owner)->create();
    $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/submissions/{$s->public_id}", $payload)
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => [$field]]]);
})->with([
    [['title' => ''], 'title'],
    [['rating' => 'x'], 'rating'],
    [['visibility' => 'x'], 'visibility'],
    [['tags' => [str_repeat('a', 101)]], 'tags.0'],
]);

it('DELETE soft-deletes and then 404s for everyone', function () {
    $owner = User::factory()->create();
    $s = Submission::factory()->for($owner)->create();

    $this->actingAs($owner, 'sanctum')->deleteJson("/api/v1/submissions/{$s->public_id}")->assertNoContent();
    expect(Submission::withTrashed()->find($s->id)->trashed())->toBeTrue();

    $this->actingAs($owner, 'sanctum')->getJson("/api/v1/submissions/{$s->public_id}")->assertNotFound();
    $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/submissions/{$s->public_id}", ['title' => 'x'])->assertNotFound();
    $this->actingAs($owner, 'sanctum')->deleteJson("/api/v1/submissions/{$s->public_id}")->assertNotFound();
    app('auth')->forgetGuards();
    $this->getJson("/api/v1/submissions/{$s->public_id}")->assertNotFound();
});
