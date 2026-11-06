<?php

use App\Support\PublicId;

it('PublicId generates 10 lowercase alphanumerics', function () {
    $seen = [];
    for ($i = 0; $i < 200; $i++) {
        $id = PublicId::generate();
        expect($id)->toMatch('/^[a-z0-9]{10}$/');
        $seen[$id] = true;
    }
    expect(count($seen))->toBe(200);
});
