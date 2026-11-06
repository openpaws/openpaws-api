<?php

use App\Exceptions\DomainError;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::prefix('api/v1')->group(function () {
        Route::post('_test/validate', fn (Request $r) => $r->validate(['title' => 'required']));
        Route::post('_test/domain', fn () => throw new DomainError('invalid_state', 'Nope.'));
        Route::get('_test/boom', fn () => throw new RuntimeException('secret detail'));
        Route::get('_test/unauth', fn () => throw new AuthenticationException);
        Route::get('_test/forbidden', fn () => throw new AuthorizationException);
        Route::get('_test/throttle', fn () => throw new ThrottleRequestsException);
    });
});

it('renders unknown routes in the error shape', function () {
    $this->getJson('/api/v1/nope')->assertStatus(404)
        ->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'Not found.']]);
});

it('renders validation errors with fields', function () {
    $this->postJson('/api/v1/_test/validate', [])->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['message', 'fields' => ['title']]]);
});

it('renders DomainError with its code', function () {
    $this->postJson('/api/v1/_test/domain')->assertStatus(422)
        ->assertExactJson(['error' => ['code' => 'invalid_state', 'message' => 'Nope.']]);
});

it('hides exception details on 500 when not debugging', function () {
    config(['app.debug' => false]);
    $this->getJson('/api/v1/_test/boom')->assertStatus(500)
        ->assertJsonPath('error.code', 'server_error')
        ->assertJsonMissingPath('error.fields')
        ->assertDontSee('secret detail');
});

it('maps auth, authorization and throttle errors', function () {
    $this->getJson('/api/v1/_test/unauth')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    $this->getJson('/api/v1/_test/forbidden')->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    $this->getJson('/api/v1/_test/throttle')->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
});

it('exposes openpaws config defaults', function () {
    expect(config('openpaws.limits.video'))->toBe(2048 * 1024 * 1024)
        ->and(config('openpaws.pagination.max'))->toBe(96)
        ->and(config('openpaws.quota.daily_bytes'))->toBe(10 * 1024 * 1024 * 1024);
});
