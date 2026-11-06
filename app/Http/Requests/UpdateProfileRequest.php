<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'bio' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'species' => ['sometimes', 'nullable', 'string', 'max:100'],
            'pronouns' => ['sometimes', 'nullable', 'string', 'max:100'],
            'links' => ['sometimes', 'array', 'max:10'],
            'links.*.label' => ['required', 'string', 'min:1', 'max:50'],
            'links.*.url' => ['required', 'string', 'max:2048', 'url:http,https'],
            'pinned_submission_ids' => ['sometimes', 'array', 'max:6'],
            // Public ids of the user's own, non-deleted submissions.
            'pinned_submission_ids.*' => ['string', 'distinct', Rule::exists('submissions', 'public_id')
                ->where('user_id', $this->user()->id)
                ->whereNull('deleted_at')],
        ];
    }
}
