<?php

namespace App\Actions;

use App\Enums\FolderKind;
use App\Models\Folder;
use App\Models\User;

class CreateDefaultFolders
{
    /** Creates gallery (position 0) and scraps (position 1) if the user lacks them. Idempotent. */
    public function handle(User $user): void
    {
        foreach ([FolderKind::Gallery, FolderKind::Scraps] as $position => $kind) {
            Folder::firstOrCreate(
                ['user_id' => $user->id, 'kind' => $kind->value],
                ['name' => ucfirst($kind->value), 'position' => $position],
            );
        }
    }

    public static function run(User $user): void
    {
        (new self)->handle($user);
    }
}
