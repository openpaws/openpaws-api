<?php

namespace App\Http\Requests;

use App\Enums\Rating;
use App\Enums\SubmissionType;
use App\Enums\Visibility;
use App\Http\Requests\Concerns\ValidatesTags;
use App\Rules\AdultConfirmedForRating;
use App\Rules\SubmissionFolder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubmissionRequest extends FormRequest
{
    use ValidatesTags;

    public function authorize(): bool
    {
        return $this->user()?->hasVerifiedEmail() === true;
    }

    public function rules(): array
    {
        $type = SubmissionType::tryFrom((string) $this->input('type'));
        $isWriting = $type === SubmissionType::Writing;

        return [
            'type' => ['required', Rule::enum(SubmissionType::class)],
            'upload_session_id' => [Rule::requiredIf($type?->requiresUpload() ?? false), 'nullable', 'string', 'max:64'],
            'cover_upload_session_id' => [Rule::prohibitedIf($type === SubmissionType::Image), 'nullable', 'string', 'max:64'],
            'title' => ['required', 'string', 'min:1', 'max:150'],
            'description' => ['nullable', 'string', 'max:20000'],
            'body' => $isWriting
                ? ['required', 'string', 'max:500000']
                : ['prohibited'],
            'rating' => ['required', Rule::enum(Rating::class), new AdultConfirmedForRating($this->user())],
            'visibility' => ['required', Rule::enum(Visibility::class)],
            'tags' => ['sometimes', 'array', 'max:200'],
            'tags.*' => ['string', 'max:100'],
            'folder' => ['sometimes', 'bail', 'string', 'max:100', new SubmissionFolder($this->user())],
        ];
    }
}
