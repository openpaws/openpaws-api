<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\PlaceSubmission;
use App\Enums\SubmissionStatus;
use App\Enums\SubmissionType;
use App\Enums\UploadKind;
use App\Exceptions\DomainError;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSubmissionRequest;
use App\Http\Requests\UpdateSubmissionRequest;
use App\Http\Resources\SubmissionResource;
use App\Jobs\MoveSubmissionMedia;
use App\Jobs\ProcessSubmission;
use App\Media\MediaPaths;
use App\Models\Submission;
use App\Models\Tag;
use App\Rules\AdultConfirmedForRating;
use App\Storage\ObjectStorage;
use App\Support\ViewCounter;
use App\Uploads\LocksUploadSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The {submission} route parameter is bound through Submission::visibleTo($viewer)
 * (see AppServiceProvider), so anything not visible to the viewer is already a 404 here.
 */
class SubmissionController extends Controller
{
    use LocksUploadSession;

    public function store(StoreSubmissionRequest $request)
    {
        $user = $request->user();
        $data = $request->validated();
        $type = SubmissionType::from($data['type']);
        $tags = $request->normalizedTags() ?? [];
        $folder = $data['folder'] ?? 'gallery';

        $submission = DB::transaction(function () use ($user, $data, $type, $tags, $folder) {
            $main = isset($data['upload_session_id'])
                ? $this->lockSession($user, 'upload_session_id', $data['upload_session_id'], $type->uploadKind())
                : null;
            $cover = isset($data['cover_upload_session_id'])
                ? $this->lockSession($user, 'cover_upload_session_id', $data['cover_upload_session_id'], UploadKind::Cover)
                : null;

            $submission = new Submission([
                'user_id' => $user->id,
                'type' => $type,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'body' => $type === SubmissionType::Writing ? $data['body'] : null,
                'rating' => $data['rating'],
                'visibility' => $data['visibility'],
                'status' => SubmissionStatus::Processing,
                'published_at' => null,
            ]);
            $submission->save();
            $submission->tags()->sync($this->resolveTagIds($tags));

            app(PlaceSubmission::class)->handle($user, $submission, $folder);

            $main?->consume();
            $cover?->consume();

            ProcessSubmission::dispatch($submission, $main?->id, $cover?->id)->afterCommit();

            return $submission;
        });

        return (new SubmissionResource($submission->load(['user', 'tags', 'assets', 'folders'])))->response()->setStatusCode(201);
    }

    public function show(Request $request, Submission $submission)
    {
        ViewCounter::record($submission, $request->user('sanctum'), (string) $request->ip(), (string) $request->userAgent());

        return (new SubmissionResource($submission->load(['user', 'tags', 'assets', 'folders'])))->withBody();
    }

    public function update(UpdateSubmissionRequest $request, Submission $submission)
    {
        $data = $request->validated();
        $folder = $data['folder'] ?? null;
        unset($data['tags'], $data['folder']);
        $tags = $request->normalizedTags();

        $affected = DB::transaction(function () use ($submission, $data, $tags, $folder, $request) {
            $affected = $submission->tags()->pluck('tags.id')->all();
            $wasPrivate = MediaPaths::isPrivate($submission->rating);
            $submission->fill($data)->save();
            if (MediaPaths::isPrivate($submission->rating) !== $wasPrivate) {
                // Crossed the adult boundary: move the files between public/ and private/.
                // MediaUrls already signs/unsigns by the new rating, so this can lag safely.
                MoveSubmissionMedia::dispatch($submission)->afterCommit();
            }
            if ($folder !== null) {
                app(PlaceSubmission::class)->handle($request->user(), $submission, $folder);
            }
            if ($tags !== null) {
                $affected = array_merge($affected, $submission->tags()->sync($this->resolveTagIds($tags))['attached']);
            }

            return $affected;
        });
        Tag::recount($affected);

        return new SubmissionResource($submission->load(['user', 'tags', 'assets', 'folders']));
    }

    public function destroy(Submission $submission)
    {
        Gate::authorize('delete', $submission);
        $tagIds = $submission->tags()->pluck('tags.id')->all();
        $submission->delete();
        Tag::recount($tagIds);

        return response()->noContent();
    }

    /**
     * POST /submissions/{publicId}/retry: reprocess a failed submission from a new upload.
     * Session rules are the same as for creation; writing may retry without a document.
     */
    public function retry(Request $request, Submission $submission, ObjectStorage $storage)
    {
        Gate::authorize('update', $submission);
        $this->ensureFailed($submission);

        $user = $request->user();
        if (! AdultConfirmedForRating::allows($user, $submission->rating)) {
            throw ValidationException::withMessages(['rating' => [AdultConfirmedForRating::MESSAGE]]);
        }
        $type = $submission->type;
        $data = $request->validate([
            'upload_session_id' => [Rule::requiredIf($type->requiresUpload()), 'nullable', 'string', 'max:64'],
            'cover_upload_session_id' => [Rule::prohibitedIf(! $type->allowsCover()), 'nullable', 'string', 'max:64'],
        ]);

        DB::transaction(function () use ($user, $data, $type, $submission, $storage) {
            $locked = Submission::whereKey($submission->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureFailed($locked);

            $main = isset($data['upload_session_id'])
                ? $this->lockSession($user, 'upload_session_id', $data['upload_session_id'], $type->uploadKind())
                : null;
            $cover = isset($data['cover_upload_session_id'])
                ? $this->lockSession($user, 'cover_upload_session_id', $data['cover_upload_session_id'], UploadKind::Cover)
                : null;

            if ($locked->assets()->delete() > 0) {
                foreach (MediaPaths::allPrefixes($locked) as $prefix) {
                    $storage->deletePrefix($prefix);
                }
            }
            $locked->forceFill(['status' => SubmissionStatus::Processing, 'published_at' => null])->save();

            $main?->consume();
            $cover?->consume();

            ProcessSubmission::dispatch($locked, $main?->id, $cover?->id)->afterCommit();
        });

        return new SubmissionResource($submission->refresh()->load(['user', 'tags', 'assets', 'folders']));
    }

    /**
     * Finds or creates the tags for already-normalised slugs.
     *
     * @param  list<string>  $slugs
     * @return list<int>
     */
    private function resolveTagIds(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        // Take row locks in slug order: concurrent requests with the same tags in a different
        // order would otherwise lock rows in opposite orders and deadlock.
        $sorted = array_values(array_unique($slugs));
        sort($sorted, SORT_STRING);

        // insertOrIgnore is race-safe against the unique slug index (concurrent creators).
        $now = now();
        Tag::insertOrIgnore(array_map(
            fn (string $slug) => ['slug' => $slug, 'usage_count' => 0, 'created_at' => $now, 'updated_at' => $now],
            $sorted,
        ));
        // A locking read sees the latest committed rows, unlike the transaction's REPEATABLE READ snapshot.
        $ids = Tag::whereIn('slug', $sorted)->orderBy('slug')->lockForUpdate()->pluck('id', 'slug');

        return array_map(
            fn (string $slug) => (int) ($ids[$slug] ?? throw new \RuntimeException("Tag [{$slug}] could not be resolved.")),
            $slugs,
        );
    }

    private function ensureFailed(Submission $submission): void
    {
        if ($submission->status !== SubmissionStatus::Failed) {
            throw new DomainError('invalid_state', 'Only submissions whose processing failed can be retried.');
        }
    }
}
