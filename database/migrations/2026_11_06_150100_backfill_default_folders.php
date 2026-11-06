<?php

use App\Actions\CreateDefaultFolders;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration: gives existing users gallery/scraps and places their non-trashed submissions
 * (oldest first, so "new goes to the end" holds) into gallery. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        User::query()->orderBy('id')->chunkById(200, function ($users) {
            foreach ($users as $user) {
                CreateDefaultFolders::run($user);
                $gallery = DB::table('folders')->where('user_id', $user->id)->where('kind', 'gallery')->value('id');
                $scraps = DB::table('folders')->where('user_id', $user->id)->where('kind', 'scraps')->value('id');

                $ids = DB::table('submissions')
                    ->where('user_id', $user->id)->whereNull('deleted_at')
                    ->whereNotIn('id', DB::table('folder_submission')->whereIn('folder_id', [$gallery, $scraps])->select('submission_id'))
                    ->orderBy('published_at')->orderBy('id')->pluck('id');

                $position = (int) (DB::table('folder_submission')->where('folder_id', $gallery)->max('position') ?? -1) + 1;
                foreach ($ids as $id) {
                    DB::table('folder_submission')->insert(['folder_id' => $gallery, 'submission_id' => $id, 'position' => $position++]);
                }
            }
        });
    }

    public function down(): void
    {
        // Nothing to undo: dropping the tables removes the data.
    }
};
