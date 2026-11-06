<?php

use App\Uploads\UploadLimits;

it('sanitises filenames into a safe last path segment', function (string $in, string $out) {
    expect(UploadLimits::sanitiseFilename($in))->toBe($out);
})->with([
    'plain' => ['photo.png', 'photo.png'],
    'spaces and symbols' => ['a b/c?.png', 'a_b_c_.png'],
    'empty' => ['', 'file'],
    'dot' => ['.', 'file'],
    'dot dot' => ['..', 'file'],
    'many dots' => ['....', 'file'],
    'leading dots' => ['..hidden.png', 'hidden.png'],
    'dotfile' => ['.png', 'png'],
    'traversal' => ['../../etc/passwd', '_.._etc_passwd'],
]);
