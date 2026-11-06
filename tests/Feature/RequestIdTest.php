<?php

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::prefix('api/v1')->get('_test/ok', fn () => ['ok' => true]);
});

it('echoes a valid X-Request-Id', function () {
    $this->getJson('/api/v1/_test/ok', ['X-Request-Id' => 'abc-123'])
        ->assertOk()->assertHeader('X-Request-Id', 'abc-123');
});

it('replaces an invalid X-Request-Id with a UUID', function () {
    $res = $this->getJson('/api/v1/_test/ok', ['X-Request-Id' => 'bad id!*']);
    expect($res->headers->get('X-Request-Id'))->toMatch('/^[0-9a-f-]{36}$/');
});

it('generates an id when absent and sets it on error responses', function () {
    $res = $this->getJson('/api/v1/nope')->assertStatus(404);
    expect($res->headers->get('X-Request-Id'))->toMatch('/^[0-9a-f-]{36}$/');
});
