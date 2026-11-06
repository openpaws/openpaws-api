<?php

namespace App\Support;

use App\Models\Folder;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FolderCounts
{
    /** Sets `listed_count` (submissions listedFor the viewer) on each folder, in one query. */
    public static function attach(Collection $folders, ?User $viewer): Collection
    {
        if ($folders->isEmpty()) {
            return $folders;
        }
        $counts = Submission::query()->listedFor($viewer)
            ->join('folder_submission as fs', 'fs.submission_id', '=', 'submissions.id')
            ->whereIn('fs.folder_id', $folders->pluck('id'))
            ->groupBy('fs.folder_id')
            ->select('fs.folder_id', DB::raw('count(*) as c'))
            ->pluck('c', 'folder_id');

        foreach ($folders as $folder) {
            $folder->setAttribute('listed_count', (int) ($counts[$folder->id] ?? 0));
        }

        return $folders;
    }
}
