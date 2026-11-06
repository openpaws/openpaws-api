<?php

use App\Enums\Rating;
use App\Models\Submission;
use App\Models\User;

it('pinned resolves through listedFor in stored order and hides unlisted or over-rating items', function () {
    $u = User::factory()->create(['username' => 'otter', 'adult_confirmed_at' => now()]);
    $a = Submission::factory()->for($u)->create(['title' => 'A']);
    $b = Submission::factory()->for($u)->create(['title' => 'B']);
    $unlisted = Submission::factory()->for($u)->create(['visibility' => 'unlisted']);
    $adult = Submission::factory()->for($u)->create(['rating' => Rating::Adult]);
    $processing = Submission::factory()->for($u)->create(['status' => 'processing', 'published_at' => null]);

    $this->actingAs($u, 'sanctum')->patchJson('/api/v1/me/profile', [
        'pinned_submission_ids' => [$b->public_id, $unlisted->public_id, $adult->public_id, $processing->public_id, $a->public_id],
    ])->assertOk()
        ->assertJsonPath('data.pinned.0.id', $b->public_id)
        ->assertJsonPath('data.pinned.1.id', $a->public_id)
        ->assertJsonCount(2, 'data.pinned');

    app('auth')->forgetGuards();
    $this->getJson('/api/v1/users/otter')->assertOk()
        ->assertJsonCount(2, 'profile.pinned')
        ->assertJsonPath('profile.pinned.0.title', 'B')
        ->assertJsonPath('profile.pinned.1.title', 'A');

    $this->actingAs(makeAdultUser(), 'sanctum')->getJson('/api/v1/users/otter')->assertOk()
        ->assertJsonCount(3, 'profile.pinned')
        ->assertJsonPath('profile.pinned.0.id', $b->public_id)
        ->assertJsonPath('profile.pinned.1.id', $adult->public_id)
        ->assertJsonPath('profile.pinned.2.id', $a->public_id);
});

it('pinned drops deleted submissions', function () {
    $u = User::factory()->create(['username' => 'otter', 'adult_confirmed_at' => now()]);
    $a = Submission::factory()->for($u)->create();
    $this->actingAs($u, 'sanctum')->patchJson('/api/v1/me/profile', ['pinned_submission_ids' => [$a->public_id]])->assertOk();
    $a->delete();
    $this->getJson('/api/v1/users/otter')->assertOk()->assertJsonPath('profile.pinned', []);
});

it('rejects pinning submissions the user does not own', function () {
    $u = User::factory()->create();
    $mine = Submission::factory()->for($u)->create();
    $theirs = Submission::factory()->create();
    $trashed = Submission::factory()->for($u)->create();
    $trashed->delete();

    foreach ([$theirs->public_id, $trashed->public_id, 'zzzzzzzzzz', 12] as $bad) {
        $this->actingAs($u, 'sanctum')->patchJson('/api/v1/me/profile', ['pinned_submission_ids' => [$mine->public_id, $bad]])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['fields' => ['pinned_submission_ids.1']]]);
    }
    $this->actingAs($u, 'sanctum')->patchJson('/api/v1/me/profile', ['pinned_submission_ids' => array_fill(0, 7, $mine->public_id)])
        ->assertStatus(422);
});
