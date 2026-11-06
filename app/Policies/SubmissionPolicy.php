<?php

namespace App\Policies;

use App\Models\Submission;
use App\Models\User;

/** Callers resolve the submission through visibleTo first (not visible => 404). */
class SubmissionPolicy
{
    public function update(User $user, Submission $submission): bool
    {
        return $submission->user_id === $user->id;
    }

    public function delete(User $user, Submission $submission): bool
    {
        return $submission->user_id === $user->id;
    }
}
