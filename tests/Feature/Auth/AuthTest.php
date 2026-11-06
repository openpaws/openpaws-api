<?php

use App\Models\User;
use App\Notifications\VerifyEmail;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

function spa()
{
    return test()->withHeader('Origin', 'http://localhost:3000')->withHeader('Referer', 'http://localhost:3000/');
}

function regPayload(array $o = []): array
{
    return array_merge([
        'username' => 'Foxy', 'display_name' => 'Foxy Fox', 'email' => 'foxy@example.com',
        'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1',
    ], $o);
}

it('registers, logs in via session, and returns me', function () {
    $c = spa();
    $c->get('/sanctum/csrf-cookie')->assertNoContent();
    $c->postJson('/api/v1/auth/register', regPayload())->assertCreated()->assertJsonPath('data.username', 'foxy');
    $c->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('data.username', 'foxy')
        ->assertJsonPath('data.display_name', 'Foxy Fox')
        ->assertJsonPath('data.email', 'foxy@example.com');
});

it('rejects duplicate usernames case-insensitively', function () {
    spa()->postJson('/api/v1/auth/register', regPayload(['username' => 'Foxy']))->assertCreated();
    spa()->postJson('/api/v1/auth/register', regPayload(['username' => 'foxy', 'email' => 'b@example.com']))
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['fields' => ['username']]]);
});

it('rejects reserved usernames', function (string $name) {
    spa()->postJson('/api/v1/auth/register', regPayload(['username' => $name]))
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['username']]]);
})->with(['admin', 'api', 'settings', 'upload', 'search', 'browse', 'login', 'register', 'me', 'openpaws', 'moderator', 'support']);

it('rejects usernames outside [a-z0-9_-]{3,30}', function (string $name) {
    spa()->postJson('/api/v1/auth/register', regPayload(['username' => $name]))
        ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['username']]]);
})->with(['ab', 'a b', str_repeat('a', 31), 'fox!']);

it('logs in by username or email', function () {
    User::factory()->create(['username' => 'otter', 'email' => 'otter@example.com', 'password' => 'pw-12345678']);
    spa()->postJson('/api/v1/auth/login', ['login' => 'Otter', 'password' => 'pw-12345678'])->assertOk()->assertJsonPath('data.username', 'otter');
    app('auth')->forgetGuards();
    spa()->postJson('/api/v1/auth/login', ['login' => 'otter@example.com', 'password' => 'pw-12345678'])->assertOk();
});

it('rejects bad password with validation_failed on login', function () {
    User::factory()->create(['username' => 'otter']);
    spa()->postJson('/api/v1/auth/login', ['login' => 'otter', 'password' => 'nope'])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['fields' => ['login']]]);
});

it('throttles login after 5 attempts per minute', function () {
    for ($i = 0; $i < 5; $i++) {
        spa()->postJson('/api/v1/auth/login', ['login' => 'x', 'password' => 'y'])->assertStatus(422);
    }
    spa()->postJson('/api/v1/auth/login', ['login' => 'x', 'password' => 'y'])
        ->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
});

it('throttles register after 3 per hour per IP', function () {
    for ($i = 0; $i < 3; $i++) {
        spa()->postJson('/api/v1/auth/register', regPayload(['username' => "user$i", 'email' => "u$i@example.com"]))->assertCreated();
    }
    spa()->postJson('/api/v1/auth/register', regPayload(['username' => 'user9', 'email' => 'u9@example.com']))
        ->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
});

it('sends a verification email on register containing a ui_url link', function () {
    Notification::fake();
    spa()->postJson('/api/v1/auth/register', regPayload())->assertCreated();
    $user = User::firstWhere('username', 'foxy');
    Notification::assertSentTo($user, VerifyEmail::class, function ($n) use ($user) {
        return str_starts_with($n->toMail($user)->actionUrl, config('openpaws.ui_url').'/verify-email?token=');
    });
});

function verifyToken(): array
{
    Notification::fake();
    spa()->postJson('/api/v1/auth/register', regPayload())->assertCreated();
    $user = User::firstWhere('username', 'foxy');
    $url = (new VerifyEmail)->url($user);
    parse_str(parse_url($url, PHP_URL_QUERY), $q);

    return [$user, $q['token']];
}

it('verifies email with a valid token', function () {
    [$user, $token] = verifyToken();
    expect($user->fresh()->email_verified_at)->toBeNull();
    spa()->postJson('/api/v1/auth/email/verify', ['token' => $token])->assertOk();
    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

it('rejects an expired token', function () {
    [, $token] = verifyToken();
    $this->travel(25)->hours();
    spa()->postJson('/api/v1/auth/email/verify', ['token' => $token])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['fields' => ['token']]]);
});

it('rejects a token for a changed email', function () {
    [$user, $token] = verifyToken();
    $user->update(['email' => 'new@example.com']);
    spa()->postJson('/api/v1/auth/email/verify', ['token' => $token])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['fields' => ['token']]]);
    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('resets password via emailed token', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'otter@example.com', 'username' => 'otter']);
    spa()->postJson('/api/v1/auth/password/forgot', ['email' => 'otter@example.com'])->assertOk();
    spa()->postJson('/api/v1/auth/password/forgot', ['email' => 'ghost@example.com'])->assertOk();
    // Only the registered address gets mail: exactly one notification, and it went to $user.
    Notification::assertCount(1);
    Notification::assertSentTimes(ResetPassword::class, 1);
    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function ($n) use (&$token, $user) {
        $token = $n->token;
        $url = call_user_func(ResetPassword::$createUrlCallback, $user, $n->token);

        return str_starts_with($url, config('openpaws.ui_url').'/reset-password?token=');
    });
    spa()->postJson('/api/v1/auth/password/reset', [
        'token' => $token, 'email' => 'otter@example.com',
        'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass',
    ])->assertOk();
    spa()->postJson('/api/v1/auth/login', ['login' => 'otter', 'password' => 'brand-new-pass'])->assertOk();
});

it('GET /me is 401 when logged out', function () {
    spa()->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('new users default to max_rating general and role user', function () {
    spa()->postJson('/api/v1/auth/register', regPayload())->assertCreated()
        ->assertJsonPath('data.max_rating', 'general')->assertJsonPath('data.role', 'user');
    $u = User::firstWhere('username', 'foxy');
    expect($u->max_rating->value)->toBe('general')->and($u->role->value)->toBe('user')->and($u->effectiveMaxRating()->value)->toBe('general');
});
