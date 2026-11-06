<?php

use App\Enums\Rating;
use App\Models\Submission;
use App\Models\User;

function browseIds($response): array
{
    return collect($response->json('data'))->pluck('id')->all();
}

it('browse new orders by published_at desc and filters type', function () {
    $u = User::factory()->create();
    $old = Submission::factory()->for($u)->create(['published_at' => now()->subDays(3)]);
    $new = Submission::factory()->for($u)->create(['published_at' => now()->subHour()]);
    $writing = Submission::factory()->for($u)->create(['type' => 'writing', 'body' => 'x', 'published_at' => now()->subMinute()]);
    Submission::factory()->for($u)->create(['visibility' => 'unlisted']);
    Submission::factory()->for($u)->create(['rating' => Rating::Adult]);

    expect(browseIds($this->getJson('/api/v1/browse')->assertOk()))
        ->toBe([$writing->public_id, $new->public_id, $old->public_id]);
    expect(browseIds($this->getJson('/api/v1/browse?type=image&sort=new')))->toBe([$new->public_id, $old->public_id]);
    $this->getJson('/api/v1/browse?type=bogus')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    $this->getJson('/api/v1/browse?per_page=1')->assertJsonCount(1, 'data')->assertJsonPath('meta.next_cursor', fn ($c) => is_string($c));
});

it('browse popular respects period', function () {
    $u = User::factory()->create();
    $dayLow = Submission::factory()->for($u)->create(['published_at' => now()->subHours(2)]);
    $weekHigh = Submission::factory()->for($u)->create(['published_at' => now()->subDays(3)]);
    $monthHigh = Submission::factory()->for($u)->create(['published_at' => now()->subDays(20)]);
    $ancient = Submission::factory()->for($u)->create(['published_at' => now()->subDays(90)]);
    foreach ([[$dayLow, 5], [$weekHigh, 50], [$monthHigh, 500], [$ancient, 5000]] as [$s, $v]) {
        Submission::whereKey($s->id)->update(['views_count' => $v]);
    }

    expect(browseIds($this->getJson('/api/v1/browse?sort=popular')))->toBe([$weekHigh->public_id, $dayLow->public_id]);
    expect(browseIds($this->getJson('/api/v1/browse?sort=popular&period=day')))->toBe([$dayLow->public_id]);
    expect(browseIds($this->getJson('/api/v1/browse?sort=popular&period=month')))->toBe([$monthHigh->public_id, $weekHigh->public_id, $dayLow->public_id]);
    expect(browseIds($this->getJson('/api/v1/browse?sort=popular&period=all')))
        ->toBe([$ancient->public_id, $monthHigh->public_id, $weekHigh->public_id, $dayLow->public_id]);
});
