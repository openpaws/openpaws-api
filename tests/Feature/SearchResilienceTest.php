<?php

use App\Enums\SubmissionStatus;
use App\Jobs\ProcessSubmission;
use App\Models\Submission;
use App\Models\Tag;
use App\Models\User;
use App\Search\TagAwareCollectionEngine;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Jobs\MakeSearchable;
use Laravel\Scout\Jobs\RemoveFromSearch;

/** Makes every index write fail, as during a Meilisearch outage. */
function breakSearchEngine(): void
{
    $manager = app(EngineManager::class);
    $manager->extend('collection', fn () => new class extends TagAwareCollectionEngine
    {
        public function update($models)
        {
            throw new RuntimeException('search is down');
        }

        public function delete($models)
        {
            throw new RuntimeException('search is down');
        }
    });
    $manager->forgetDrivers();
}

it('syncs the index through the default queue after commit by default', function () {
    expect(config('scout.queue'))->toMatchArray(['queue' => 'default'])
        ->and(config('scout.after_commit'))->toBeTrue();

    Queue::fake();
    $s = Submission::factory()->create();
    $s->unsetRelation('tags')->searchable();
    $s->delete();

    Queue::assertPushedOn('default', MakeSearchable::class, fn (MakeSearchable $job) => $job->tries === 5);
    Queue::assertPushedOn('default', RemoveFromSearch::class);
});

it('publishes even when the search engine fails', function () {
    breakSearchEngine();
    Log::spy();
    $owner = User::factory()->create();
    $s = Submission::factory()->for($owner)->create([
        'type' => 'writing', 'body' => "# hi\n\ntext", 'status' => SubmissionStatus::Processing, 'published_at' => null,
    ]);
    $tag = Tag::create(['slug' => 'fox']);
    $s->tags()->sync([$tag->id]);

    ProcessSubmission::dispatchSync($s);

    expect($s->fresh()->status)->toBe(SubmissionStatus::Published)
        ->and($tag->fresh()->usage_count)->toBe(1);
    Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains($msg, 'search index'))->atLeast()->once();
});

it('committed writes do not 500 when the search engine fails', function () {
    breakSearchEngine();
    $owner = User::factory()->create(['email_verified_at' => now()]);

    $id = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/submissions', [
        'type' => 'writing', 'title' => 'T', 'body' => 'words', 'rating' => 'general', 'visibility' => 'public', 'tags' => ['fox'],
    ])->assertCreated()->json('data.id');
    expect(Submission::where('public_id', $id)->first()->status)->toBe(SubmissionStatus::Published);

    $this->patchJson("/api/v1/submissions/{$id}", ['title' => 'U', 'tags' => ['wolf']])->assertOk()->assertJsonPath('data.title', 'U');
    $this->deleteJson("/api/v1/submissions/{$id}")->assertNoContent();
    expect(Submission::withTrashed()->where('public_id', $id)->first()->trashed())->toBeTrue();
});
