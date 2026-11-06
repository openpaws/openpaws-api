<?php

namespace App\Rules;

use App\Models\Folder;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** `gallery`, `scraps`, or the public id of one of the user's own custom folders. */
class SubmissionFolder implements ValidationRule
{
    public function __construct(private ?User $user) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The folder is invalid.');

            return;
        }
        if (in_array($value, ['gallery', 'scraps'], true)) {
            return;
        }
        $ok = $this->user !== null && Folder::where('user_id', $this->user->id)
            ->where('kind', 'custom')->where('public_id', $value)->exists();
        if (! $ok) {
            $fail('The selected folder does not exist.');
        }
    }
}
