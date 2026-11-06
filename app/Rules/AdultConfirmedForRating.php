<?php

namespace App\Rules;

use App\Enums\Rating;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A mature or adult rating may only be set by an uploader who has confirmed they are 18+. */
class AdultConfirmedForRating implements ValidationRule
{
    public const MESSAGE = 'Confirm you are 18+ before rating a submission mature or adult.';

    public function __construct(private readonly ?User $uploader) {}

    public static function allows(?User $uploader, Rating|string|null $rating): bool
    {
        $rating = $rating instanceof Rating ? $rating : Rating::tryFrom((string) $rating);

        return $rating === null || $rating === Rating::General || $uploader?->adult_confirmed_at !== null;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::allows($this->uploader, is_string($value) ? $value : null)) {
            $fail(self::MESSAGE);
        }
    }
}
