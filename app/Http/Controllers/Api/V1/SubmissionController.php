<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SubmissionStatus;
use App\Enums\SubmissionType;
use App\Enums\UploadKind;
use App\Enums\UploadStatus;
use App\Exceptions\DomainError;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSubmissionRequest;
use App\Http\Requests\UpdateSubmissionRequest;
use App\Http\Resources\SubmissionResource;
use App\Jobs\ProcessSubmission;
use App\Models\Submission;
use App\Models\UploadSession;
use App\Models\User;
use App\Support\ViewCounter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The {submission} route parameter is bound through Submission::visibleTo($viewer)
 * (see AppServiceProvider), so anything not visible to the viewer is already a 404 here.
 */
class SubmissionController extends Controller
{
    public function store(StoreSubmissionRequest $request)
    {
        $user = $request->user();
        $data = $request->validated();
        $type = SubmissionType::from($data['type']);
        // Tags and folder are validated by the request; they are persisted from Tasks 8 and 10.

        $submission = DB::transaction(function () use ($user, $data, $type) {
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

            $main?->consume();
            $cover?->consume();

            ProcessSubmission::dispatch($submission, $main?->id, $cover?->id)->afterCommit();

            return $submission;
        });

        return (new SubmissionResource($submission->load('user')))->response()->setStatusCode(201);
    }

    public function show(Request $request, Submission $submission)
    {
        ViewCounter::record($submission, $request->user('sanctum'), (string) $request->ip(), (string) $request->userAgent());

        return (new SubmissionResource($submission->load('user')))->withBody();
    }

    public function update(UpdateSubmissionRequest $request, Submission $submission)
    {
        $data = $request->validated();
        unset($data['tags'], $data['folder']); // validated only until Tasks 8 and 10
        $submission->fill($data)->save();

        return new SubmissionResource($submission->load('user'));
    }

    public function destroy(Submission $submission)
    {
        Gate::authorize('delete', $submission);
        $submission->delete();

        return response()->noContent();
    }

    /**
     * Locks the caller's upload session row and checks it can back a new submission.
     * Throws (rolling back the transaction) when it is missing, foreign, the wrong kind or not usable.
     */
    private function lockSession(User $user, string $field, string $id, UploadKind $kind): UploadSession
    {
        $session = UploadSession::whereKey($id)->where('user_id', $user->id)->lockForUpdate()->first();
        if ($session === null) {
            throw ValidationException::withMessages([$field => ['The upload was not found.']]);
        }
        if ($session->kind !== $kind) {
            throw ValidationException::withMessages([$field => ["This upload must be of kind {$kind->value}."]]);
        }

        return match ($session->status) {
            UploadStatus::Uploaded => $session,
            UploadStatus::Pending => throw new DomainError('upload_not_complete', 'The upload has not been completed.'),
            UploadStatus::Consumed, UploadStatus::Expired => throw new DomainError('upload_already_used', 'This upload can no longer be used.'),
        };
    }
}
