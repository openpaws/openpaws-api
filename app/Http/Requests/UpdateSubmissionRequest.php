<?php

namespace App\Http\Requests;

use App\Enums\Rating;
use App\Enums\SubmissionType;
use App\Enums\Visibility;
use App\Http\Requests\Concerns\ValidatesTags;
use App\Rules\AdultConfirmedForRating;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The {submission} route binding has already resolved through visibleTo (else 404),
 * so authorize() gives 403 to non-owners before any field is validated.
 */
class UpdateSubmissionRequest extends FormRequest
{
    use ValidatesTags;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('submission')) === true;
    }

    public function rules(): array
    {
        $submission = $this->route('submission');
        $isWriting = $submission?->type === SubmissionType::Writing;

        return [
            'title' => ['sometimes', 'required', 'string', 'min:1', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'body' => $isWriting
                ? ['sometimes', 'required', 'string', 'max:500000']
                : ['prohibited'],
            'rating' => ['sometimes', 'required', Rule::enum(Rating::class), new AdultConfirmedForRating($this->user())],
            'visibility' => ['sometimes', 'required', Rule::enum(Visibility::class)],
            'tags' => ['sometimes', 'array', 'max:200'],
            'tags.*' => ['string', 'max:100'],
            'folder' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
