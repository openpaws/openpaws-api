<?php

namespace App\Actions;

use App\Enums\FolderKind;
use App\Models\Folder;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Folder placement rules: a submission is in exactly one of gallery/scraps, plus any number
 * of custom folders. New memberships go to the top of the folder.
 */
class PlaceSubmission
{
    /** $key is `gallery`, `scraps` or the public id of one of the user's custom folders. */
    public function handle(User $user, Submission $submission, string $key): void
    {
        DB::transaction(function () use ($user, $submission, $key) {
            // Lock order: the submission row first, then folder rows. This serialises concurrent
            // moves of the same submission, so it can never end up in both gallery and scraps.
            Submission::withTrashed()->whereKey($submission->id)->lockForUpdate()->first();
            CreateDefaultFolders::run($user);
            $base = fn (string $kind) => Folder::where('user_id', $user->id)->where('kind', $kind)->firstOrFail();

            if ($key === 'gallery' || $key === 'scraps') {
                $other = $key === 'gallery' ? 'scraps' : 'gallery';
                $submission->folders()->detach($base($other)->id);
                $this->append($base($key), $submission);

                return;
            }

            $custom = Folder::where('user_id', $user->id)->where('kind', FolderKind::Custom->value)
                ->where('public_id', $key)->firstOrFail();
            $this->append($custom, $submission);

            $inBase = $submission->folders()->whereIn('folders.kind', ['gallery', 'scraps'])->exists();
            if (! $inBase) {
                $this->append($base('gallery'), $submission);
            }
        });
    }

    /** Adds the submission at the top (min(position)-1, or 0 when empty); no-op when already a member. */
    public function append(Folder $folder, Submission $submission): void
    {
        Folder::whereKey($folder->id)->lockForUpdate()->first(); // serialise position allocation per folder
        if (DB::table('folder_submission')->where('folder_id', $folder->id)->where('submission_id', $submission->id)->exists()) {
            return;
        }
        $min = DB::table('folder_submission')->where('folder_id', $folder->id)->min('position');
        DB::table('folder_submission')->insert([
            'folder_id' => $folder->id, 'submission_id' => $submission->id, 'position' => $min === null ? 0 : $min - 1,
        ]);
    }
}
