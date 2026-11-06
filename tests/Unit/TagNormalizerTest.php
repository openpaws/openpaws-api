<?php

use App\Models\Tag;
use App\Models\TagAlias;
use App\Support\TagNormalizer;

it('collapses Wolf, " wolf ", wolf! to wolf', function () {
    expect(TagNormalizer::normalize(['Wolf', ' wolf ', 'wolf!']))->toBe(['wolf']);
});

it('drops "!!!" and "   "', function () {
    expect(TagNormalizer::normalize(['!!!', '   ', 'ok']))->toBe(['ok']);
});

it('turns "red fox" into red_fox', function () {
    expect(TagNormalizer::normalize(['red fox', '__a__  b__']))->toBe(['red_fox', 'a_b']);
});

it('truncates to 50', function () {
    $out = TagNormalizer::normalize([str_repeat('a', 80)]);
    expect($out)->toBe([str_repeat('a', 50)]);
});

it('keeps order and ignores non-strings', function () {
    expect(TagNormalizer::normalize(['b', 'a', 'B', 5, null]))->toBe(['b', 'a']);
});

it('resolves aliases to the target tag slug and de-duplicates', function () {
    $tag = Tag::create(['slug' => 'canine']);
    TagAlias::create(['alias_slug' => 'doggo', 'tag_id' => $tag->id]);

    expect(TagNormalizer::normalize(['Doggo', 'canine', 'x']))->toBe(['canine', 'x']);
});
