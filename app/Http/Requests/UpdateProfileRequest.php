<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'pinned_submission_ids.*' => ['integer'],
        ];
    }
}
