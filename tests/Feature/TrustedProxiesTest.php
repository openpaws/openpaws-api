<?php

use Illuminate\Support\Facades\Route;

// Ruling U10: the UI's SSR calls come from the UI server and forward the browser's IP in
// X-Forwarded-For; only proxies listed in TRUSTED_PROXIES may set the client IP that way.

beforeEach(function () {
    config(['trustedproxy.proxies' => ['10.0.0.1']]);
    Route::prefix('api/v1')->get('_test/ip', fn (Illuminate\Http\Request $r) => ['ip' => $r->ip()]);
});

$from = fn (string $remote, string $xff) => ['REMOTE_ADDR' => $remote, 'HTTP_X_FORWARDED_FOR' => $xff];

it('defaults to trusting only 127.0.0.1', function () {
    expect(require base_path('config/trustedproxy.php'))->toMatchArray(['proxies' => ['127.0.0.1']]);
});

it('parses a comma-separated TRUSTED_PROXIES', function () {
    $_ENV['TRUSTED_PROXIES'] = ' 10.0.0.1, 10.0.0.2 ,';
    $_SERVER['TRUSTED_PROXIES'] = $_ENV['TRUSTED_PROXIES'];
    putenv('TRUSTED_PROXIES='.$_ENV['TRUSTED_PROXIES']);
    try {
        expect((require base_path('config/trustedproxy.php'))['proxies'])->toBe(['10.0.0.1', '10.0.0.2']);
    } finally {
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
        putenv('TRUSTED_PROXIES');
    }
});

it('uses the forwarded IP only when the request comes from a trusted proxy', function () use ($from) {
    $this->withServerVariables($from('10.0.0.1', '203.0.113.7'))->getJson('/api/v1/_test/ip')
        ->assertOk()->assertJson(['ip' => '203.0.113.7']);
    $this->withServerVariables($from('10.0.0.9', '203.0.113.7'))->getJson('/api/v1/_test/ip')
        ->assertOk()->assertJson(['ip' => '10.0.0.9']);
});

it('rate-limits by the forwarded IP behind a trusted proxy', function () use ($from) {
    // register: 3/hour per IP. Empty bodies fail validation but still count.
    foreach (range(1, 3) as $_) {
        $this->withServerVariables($from('10.0.0.1', '203.0.113.7'))->postJson('/api/v1/auth/register', [])->assertStatus(422);
    }
    $this->withServerVariables($from('10.0.0.1', '203.0.113.7'))->postJson('/api/v1/auth/register', [])->assertStatus(429);
    // Another browser behind the same UI server has its own budget.
    $this->withServerVariables($from('10.0.0.1', '203.0.113.8'))->postJson('/api/v1/auth/register', [])->assertStatus(422);
});

it('ignores X-Forwarded-For from an untrusted address', function () use ($from) {
    foreach (range(1, 3) as $i) {
        $this->withServerVariables($from('10.0.0.9', "198.51.100.$i"))->postJson('/api/v1/auth/register', [])->assertStatus(422);
    }
    // A fresh spoofed X-Forwarded-For does not buy a new budget.
    $this->withServerVariables($from('10.0.0.9', '198.51.100.99'))->postJson('/api/v1/auth/register', [])->assertStatus(429);
});
