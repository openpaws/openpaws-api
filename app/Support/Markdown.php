<?php

namespace App\Support;

use Illuminate\Support\Str;

class Markdown
{
    public static function toSafeHtml(?string $md): string
    {
        if ($md === null || $md === '') {
            return '';
        }

        return trim((string) Str::markdown($md, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]));
    }
}
