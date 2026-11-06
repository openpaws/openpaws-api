<?php

use App\Support\Markdown;

it('strips script tags, raw html, and javascript: links', function () {
    $out = Markdown::toSafeHtml('<script>x</script>[a](javascript:alert(1))<img src=x onerror=y>');
    expect($out)->not->toContain('<script')->not->toContain('javascript:')->not->toContain('onerror');
});

it('renders basic markdown and handles null', function () {
    expect(Markdown::toSafeHtml('**hi**'))->toContain('<strong>hi</strong>');
    expect(Markdown::toSafeHtml(null))->toBe('');
});

it('drops javascript: and other unsafe link targets on their own', function (string $md) {
    $out = Markdown::toSafeHtml($md);
    // The link is still rendered (an autolink keeps its text), but never with the unsafe target.
    expect($out)->toContain('<a')
        ->not->toMatch('/href\s*=\s*["\']?\s*(javascript|vbscript|data):/i')
        ->not->toMatch('/href\s*=\s*["\']?[^"\'>]*(javascript|vbscript):/i');
})->with([
    'inline link' => ['[click](javascript:alert(1))'],
    'mixed case scheme' => ['[click](JaVaScRiPt:alert(1))'],
    'autolink' => ['<javascript:alert(1)>'],
    'reference link' => ["[click][x]\n\n[x]: javascript:alert(1)"],
    'vbscript' => ['[click](vbscript:msgbox(1))'],
    'data url' => ['[click](data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==)'],
]);

it('keeps safe links', function () {
    expect(Markdown::toSafeHtml('[site](https://example.com)'))->toContain('href="https://example.com"');
});
