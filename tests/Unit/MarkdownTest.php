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
