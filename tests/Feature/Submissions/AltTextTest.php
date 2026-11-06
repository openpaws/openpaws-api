<?php

use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

// Ruling U11 (spec §7: alt text fields on image uploads).

function postImageWithAlt($test, User $u, array $over = [])
{
    return $test->actingAs($u, 'sanctum')->postJson('/api/v1/submissions', $over + [
        'type' => 'image', 'upload_session_id' => makeUploadSession($u, 'image')->id, 'title' => 'Fox',
        'rating' => 'general', 'visibility' => 'public',
    ]);
}

it('stores alt_text on create and exposes it', function () {
    Queue::fake();
    $u = User::factory()->create();

    postImageWithAlt($this, $u, ['alt_text' => 'A red fox curled up in snow.'])
        ->assertCreated()->assertJsonPath('data.alt_text', 'A red fox curled up in snow.');
    expect(Submission::sole()->alt_text)->toBe('A red fox curled up in snow.');
});

it('alt_text is optional and null when omitted or empty', function () {
    Queue::fake();
    $u = User::factory()->create();

    postImageWithAlt($this, $u)
        ->assertCreated()->assertJsonPath('data.alt_text', null);
    postImageWithAlt($this, $u, ['alt_text' => ''])
        ->assertCreated()->assertJsonPath('data.alt_text', null);
});

it('rejects alt_text over 500 characters on create and PATCH', function () {
    Queue::fake();
    $u = User::factory()->create();

    postImageWithAlt($this, $u, ['alt_text' => str_repeat('a', 501)])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['alt_text']]]);
    postImageWithAlt($this, $u, ['alt_text' => str_repeat('a', 500)])
        ->assertCreated();

    $sub = Submission::factory()->for($u)->create();
    $this->actingAs($u, 'sanctum')->patchJson("/api/v1/submissions/{$sub->public_id}", ['alt_text' => str_repeat('a', 501)])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['alt_text']]]);
    $this->actingAs($u, 'sanctum')->patchJson("/api/v1/submissions/{$sub->public_id}", ['alt_text' => ['x']])
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['alt_text']]]);
});

it('owner can set and clear alt_text with PATCH', function () {
    $u = User::factory()->create();
    $sub = Submission::factory()->for($u)->create();

    $this->actingAs($u, 'sanctum')->patchJson("/api/v1/submissions/{$sub->public_id}", ['alt_text' => 'Two foxes.'])
        ->assertOk()->assertJsonPath('data.alt_text', 'Two foxes.');
    expect($sub->fresh()->alt_text)->toBe('Two foxes.');

    $this->actingAs($u, 'sanctum')->patchJson("/api/v1/submissions/{$sub->public_id}", ['alt_text' => null])
        ->assertOk()->assertJsonPath('data.alt_text', null);
    expect($sub->fresh()->alt_text)->toBeNull();
});

it('shows alt_text to viewers on the show endpoint', function () {
    $sub = Submission::factory()->create(['alt_text' => 'Described.']);
    $this->getJson("/api/v1/submissions/{$sub->public_id}")->assertOk()->assertJsonPath('data.alt_text', 'Described.');
});
