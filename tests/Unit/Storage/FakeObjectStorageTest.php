<?php

use App\Storage\FakeObjectStorage;

it('stores, reports, downloads and deletes objects', function () {
    $s = new FakeObjectStorage;
    $f = tempnam(sys_get_temp_dir(), 'fx');
    file_put_contents($f, 'hello');

    expect($s->exists('a/b.txt'))->toBeFalse()->and($s->size('a/b.txt'))->toBeNull();
    $s->simulateUpload('a/b.txt', $f);
    expect($s->exists('a/b.txt'))->toBeTrue()->and($s->size('a/b.txt'))->toBe(5);
    expect(file_get_contents($s->downloadToTemp('a/b.txt')))->toBe('hello');

    $s->putFile('a/c.txt', $f, 'text/plain');
    $s->deletePrefix('a/');
    expect($s->exists('a/b.txt'))->toBeFalse()->and($s->exists('a/c.txt'))->toBeFalse();
});

it('produces fake presigned urls and expiring temporary urls', function () {
    $s = new FakeObjectStorage;
    expect($s->presignPut('p/x', 'image/png', 60))->toBe('fake://put/p/x');
    expect($s->temporaryUrl('p/x', 60))->toMatch('#^fake://temp/p/x\?expires=\d+$#');
    config(['openpaws.cdn_url' => 'https://cdn.test/']);
    expect($s->publicUrl('p/x'))->toBe('https://cdn.test/p/x');
});
