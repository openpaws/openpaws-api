<?php

use App\Models\Submission;
use App\Models\User;

it('views increment once per viewer per day and not for the owner', function () {
    $owner = User::factory()->create();
    $s = Submission::factory()->for($owner)->create();
    $url = "/api/v1/submissions/{$s->public_id}";
    $updatedAt = $s->updated_at;

    $this->travel(5)->minutes();
    $this->actingAs($owner, 'sanctum')->getJson($url)->assertOk();
    expect($s->fresh()->views_count)->toBe(0);

    $viewer = User::factory()->create();
    $this->actingAs($viewer, 'sanctum')->getJson($url)->assertOk();
    $this->actingAs($viewer, 'sanctum')->getJson($url)->assertOk();
    expect($s->fresh()->views_count)->toBe(1);

    app('auth')->forgetGuards();
    $this->getJson($url, ['User-Agent' => 'A'])->assertOk();
    $this->getJson($url, ['User-Agent' => 'A'])->assertOk();
    expect($s->fresh()->views_count)->toBe(2);
    $this->getJson($url, ['User-Agent' => 'B'])->assertOk(); // different guest fingerprint
    expect($s->fresh()->views_count)->toBe(3);

    $this->travel(1)->days();
    $this->actingAs($viewer, 'sanctum')->getJson($url)->assertOk();
    expect($s->fresh()->views_count)->toBe(4);
    expect($s->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
});

it('does not count views of unpublished submissions', function () {
    $owner = User::factory()->create();
    $s = Submission::factory()->for($owner)->create(['status' => 'processing', 'published_at' => null]);
    $this->actingAs($owner, 'sanctum')->getJson("/api/v1/submissions/{$s->public_id}")->assertOk();
    expect($s->fresh()->views_count)->toBe(0);
});
