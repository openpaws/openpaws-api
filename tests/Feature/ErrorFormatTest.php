<?php

use App\Exceptions\DomainError;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Gate::define('test-deny', fn () => Response::deny('You shall not pass.'));
    Gate::define('test-false', fn () => false);
    Route::prefix('api/v1')->group(function () {
        Route::post('_test/validate', fn (Request $r) => $r->validate(['title' => 'required']));
        Route::post('_test/domain', fn () => throw new DomainError('invalid_state', 'Nope.'));
        Route::get('_test/boom', fn () => throw new RuntimeException('secret detail'));
        Route::get('_test/unauth', fn () => throw new AuthenticationException);
        Route::get('_test/forbidden', fn () => throw new AuthorizationException);
        Route::get('_test/forbidden-default', fn () => throw new AuthorizationException('This action is unauthorized.'));
        Route::get('_test/forbidden-msg', fn () => throw new AuthorizationException('Only the owner can do that.'));
        Route::get('_test/denied', fn () => Gate::authorize('test-deny'));
        Route::get('_test/gate', fn () => Gate::authorize('test-false'));
        Route::get('_test/abort-msg', fn () => abort(403, 'Aborted with a reason.'));
        Route::get('_test/abort', fn () => abort(403));
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

it('keeps specific 403 messages and falls back to a generic one', function (string $path, string $message) {
    $this->actingAs(User::factory()->create()); // gate callbacks only run for a user
    $this->getJson('/api/v1/_test/'.$path)->assertStatus(403)
        ->assertExactJson(['error' => ['code' => 'forbidden', 'message' => $message]]);
})->with([
    'no message' => ['forbidden', 'Forbidden.'],
    'framework default message' => ['forbidden-default', 'Forbidden.'],
    'gate returning false' => ['gate', 'Forbidden.'],
    'abort without message' => ['abort', 'Forbidden.'],
    'specific message' => ['forbidden-msg', 'Only the owner can do that.'],
    'Response::deny message' => ['denied', 'You shall not pass.'],
    'abort with message' => ['abort-msg', 'Aborted with a reason.'],
]);

it('exposes openpaws config defaults', function () {
    expect(config('openpaws.limits.video'))->toBe(2048 * 1024 * 1024)
        ->and(config('openpaws.pagination.max'))->toBe(96)
        ->and(config('openpaws.quota.daily_bytes'))->toBe(10 * 1024 * 1024 * 1024);
});
