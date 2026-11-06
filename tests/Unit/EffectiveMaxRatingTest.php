<?php

use App\Enums\Rating;
use App\Models\User;

it('computes effective max rating', function (bool $confirmed, string $max, string $expected) {
    $u = new User(['username' => 'x']);
    $u->max_rating = Rating::from($max);
    $u->adult_confirmed_at = $confirmed ? now() : null;
    expect($u->effectiveMaxRating()->value)->toBe($expected);
})->with([
    [false, 'general', 'general'], [false, 'mature', 'general'], [false, 'adult', 'general'],
    [true, 'general', 'general'], [true, 'mature', 'mature'], [true, 'adult', 'adult'],
]);
