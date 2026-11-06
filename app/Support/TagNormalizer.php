<?php

namespace App\Support;

use App\Models\TagAlias;

class TagNormalizer
{
    public const MAX_LENGTH = 50;

    /**
     * Normalises raw tags to slugs, de-duplicates in order, then resolves aliases.
     *
     * @param  array<mixed>  $raw
     * @return list<string>
     */
    public static function normalize(array $raw): array
    {
        $slugs = [];
        foreach ($raw as $item) {
            if (! is_string($item)) {
                continue;
            }
            $slug = self::slug($item);
            if ($slug !== '') {
                $slugs[$slug] = true;
            }
        }
        $slugs = array_map('strval', array_keys($slugs));
        if ($slugs === []) {
            return [];
        }

        $map = TagAlias::query()->whereIn('alias_slug', $slugs)
            ->join('tags', 'tags.id', '=', 'tag_aliases.tag_id')
            ->pluck('tags.slug', 'tag_aliases.alias_slug')->all();

        $resolved = [];
        foreach ($slugs as $slug) {
            $resolved[(string) ($map[$slug] ?? $slug)] = true;
        }

        return array_map('strval', array_keys($resolved));
    }

    /** Normalises a single string without alias resolution ('' when nothing is left). */
    public static function slug(string $value): string
    {
        $s = mb_strtolower(trim($value));
        $s = preg_replace('/\s+/u', '_', $s);
        $s = preg_replace('/[^a-z0-9_]/', '', $s);
        $s = trim(preg_replace('/_+/', '_', $s), '_');
        $s = substr($s, 0, self::MAX_LENGTH);

        return trim($s, '_');
    }
}
