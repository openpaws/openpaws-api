<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\PlaceSubmission;
use App\Exceptions\DomainError;
use App\Http\Controllers\Controller;
use App\Http\Resources\FolderResource;
use App\Models\Folder;
use App\Models\Submission;
use App\Support\FolderCounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;

/** Folder writes. Folders are only addressable by their owner here: anyone else gets 404. */
class FolderController extends Controller
{
    private const MAX_CUSTOM = 100;

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:60', $this->uniqueName($user->id)],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $folder = DB::transaction(function () use ($user, $data) {
            // Lock the user row so concurrent creates cannot exceed the cap or share a position.
            $user->newQuery()->whereKey($user->id)->lockForUpdate()->first();
            $custom = Folder::where('user_id', $user->id)->where('kind', 'custom');
            if ((clone $custom)->count() >= self::MAX_CUSTOM) {
                throw ValidationException::withMessages(['name' => ['You can have at most '.self::MAX_CUSTOM.' custom folders.']]);
            }

            return Folder::create([
                'user_id' => $user->id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'kind' => 'custom',
                'position' => max(2, (int) Folder::where('user_id', $user->id)->max('position') + 1),
            ]);
        });

        return (new FolderResource(FolderCounts::attach(collect([$folder]), $user)->first()))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $publicId)
    {
        $folder = $this->owned($request, $publicId);
        $this->ensureCustom($folder);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:60', $this->uniqueName($folder->user_id, $folder->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $folder->fill($data)->save();

        return new FolderResource(FolderCounts::attach(collect([$folder]), $request->user())->first());
    }

    public function destroy(Request $request, string $publicId)
    {
        $folder = $this->owned($request, $publicId);
        $this->ensureCustom($folder);

        DB::transaction(function () use ($folder) {
            $base = Folder::where('user_id', $folder->user_id)->whereIn('kind', ['gallery', 'scraps'])->pluck('id');
            $gallery = Folder::where('user_id', $folder->user_id)->where('kind', 'gallery')->firstOrFail();
            // Submissions that would be left in no gallery/scraps folder go to gallery.
            $orphans = Submission::withTrashed()
                ->whereIn('id', DB::table('folder_submission')->where('folder_id', $folder->id)->select('submission_id'))
                ->whereNotIn('id', DB::table('folder_submission')->whereIn('folder_id', $base)->select('submission_id'))
                ->orderBy('id')->get();
            foreach ($orphans as $submission) {
                app(PlaceSubmission::class)->append($gallery, $submission);
            }
            $folder->delete(); // cascades folder_submission
        });

        return response()->noContent();
    }

    public function order(Request $request, string $publicId)
    {
        $folder = $this->owned($request, $publicId);
        $data = $request->validate([
            'submission_ids' => ['present', 'array'],
            'submission_ids.*' => ['string'],
        ]);
        $given = array_values($data['submission_ids']);

        DB::transaction(function () use ($folder, $given, $request) {
            Folder::whereKey($folder->id)->lockForUpdate()->first();
            // The set the owner sees in GET /users/{u}/submissions?folder=: listedFor(owner) items.
            $listed = Submission::query()->listedFor($request->user())
                ->join('folder_submission as fs', 'fs.submission_id', '=', 'submissions.id')
                ->where('fs.folder_id', $folder->id)
                ->pluck('submissions.id', 'submissions.public_id');

            if (count($given) !== count(array_unique($given)) || count($given) !== $listed->count()
                || array_diff($given, $listed->keys()->all()) !== []) {
                throw ValidationException::withMessages(['submission_ids' => ['The list must contain exactly the submissions listed in the folder.']]);
            }
            // Items not in the listing keep their relative order, after the reordered ones.
            $rest = DB::table('folder_submission')->where('folder_id', $folder->id)
                ->whereNotIn('submission_id', $listed->values())
                ->orderBy('position')->orderBy('submission_id')->pluck('submission_id');
            $ids = array_merge(array_map(fn ($id) => $listed[$id], $given), $rest->all());
            foreach ($ids as $position => $submissionId) {
                DB::table('folder_submission')->where('folder_id', $folder->id)
                    ->where('submission_id', $submissionId)->update(['position' => $position]);
            }
        });

        return response()->noContent();
    }

    private function owned(Request $request, string $publicId): Folder
    {
        return Folder::where('user_id', $request->user()->id)->where('public_id', $publicId)->firstOrFail();
    }

    private function ensureCustom(Folder $folder): void
    {
        if (! $folder->isCustom()) {
            throw new DomainError('invalid_state', 'Gallery and Scraps cannot be renamed or deleted.');
        }
    }

    /** Unique per user, case-insensitive (the column collation is case-insensitive too). */
    private function uniqueName(int $userId, ?int $ignoreId = null): Unique
    {
        return Rule::unique('folders', 'name')->where('user_id', $userId)->ignore($ignoreId);
    }
}
