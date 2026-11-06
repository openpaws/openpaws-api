<?php

namespace App\Http\Requests\Concerns;

use App\Support\TagNormalizer;
use Illuminate\Validation\Validator;

trait ValidatesTags
{
    public const MAX_TAGS = 60;

    /** Normalised, alias-resolved slugs from the validated `tags` input (null when not sent). */
    public function normalizedTags(): ?array
    {
        return $this->has('tags') ? TagNormalizer::normalize((array) $this->input('tags')) : null;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->has('tags') || $v->errors()->has('tags.*')) {
                return;
            }
            if (count($this->normalizedTags() ?? []) > self::MAX_TAGS) {
                $v->errors()->add('tags', 'A submission can have at most '.self::MAX_TAGS.' tags.');
            }
        });
    }
}
