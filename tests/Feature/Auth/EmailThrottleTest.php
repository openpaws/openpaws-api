<?php

use App\Models\User;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

function fromIp(string $ip)
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip]);
}

it('limits verification resends to 3 per 10 minutes per user', function () {
    $u = User::factory()->unverified()->create();
    foreach (range(1, 3) as $i) {
        $this->actingAs($u, 'sanctum')->postJson('/api/v1/auth/email/resend')->assertOk();
    }
    $this->actingAs($u, 'sanctum')->postJson('/api/v1/auth/email/resend')
        ->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');

    // Per user, not global.
    app('auth')->forgetGuards();
    $this->actingAs(User::factory()->unverified()->create(), 'sanctum')->postJson('/api/v1/auth/email/resend')->assertOk();

    $this->travel(11)->minutes();
    app('auth')->forgetGuards();
    $this->actingAs($u, 'sanctum')->postJson('/api/v1/auth/email/resend')->assertOk();
});

it('limits password/forgot to 3 per hour per email, whatever the IP', function () {
    foreach (range(1, 3) as $i) {
        fromIp("10.0.0.$i")->postJson('/api/v1/auth/password/forgot', ['email' => 'Victim@example.com'])->assertOk();
    }
    fromIp('10.0.0.9')->postJson('/api/v1/auth/password/forgot', ['email' => 'victim@example.com'])
        ->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    fromIp('10.0.0.9')->postJson('/api/v1/auth/password/forgot', ['email' => 'other@example.com'])->assertOk();
});

it('limits password/forgot to 5 per hour per IP', function () {
    foreach (range(1, 5) as $i) {
        fromIp('10.0.0.1')->postJson('/api/v1/auth/password/forgot', ['email' => "u$i@example.com"])->assertOk();
    }
    fromIp('10.0.0.1')->postJson('/api/v1/auth/password/forgot', ['email' => 'u6@example.com'])
        ->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    fromIp('10.0.0.2')->postJson('/api/v1/auth/password/forgot', ['email' => 'u6@example.com'])->assertOk();
});

it('limits password/reset to 10 per hour per IP', function () {
    $payload = ['token' => 'bad', 'email' => 'a@example.com', 'password' => 'secret123', 'password_confirmation' => 'secret123'];
    foreach (range(1, 10) as $i) {
        fromIp('10.0.0.1')->postJson('/api/v1/auth/password/reset', $payload)->assertStatus(422);
    }
    fromIp('10.0.0.1')->postJson('/api/v1/auth/password/reset', $payload)
        ->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    fromIp('10.0.0.2')->postJson('/api/v1/auth/password/reset', $payload)->assertStatus(422);
});

it('limits email changes on PATCH /me to 5 per hour per user, leaving other edits alone', function () {
    $u = User::factory()->create(['email' => 'me@example.com']);
    $this->actingAs($u, 'sanctum');

    // Not an email change: never throttled.
    foreach (range(1, 8) as $i) {
        $this->patchJson('/api/v1/me', ['display_name' => "Name $i"])->assertOk();
        $this->patchJson('/api/v1/me', ['email' => $u->fresh()->email])->assertOk();
    }
    foreach (range(1, 5) as $i) {
        $this->patchJson('/api/v1/me', ['email' => "new$i@example.com"])->assertOk();
    }
    $this->patchJson('/api/v1/me', ['email' => 'victim@example.com'])
        ->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    expect($u->fresh()->email)->toBe('new5@example.com');
    $this->patchJson('/api/v1/me', ['display_name' => 'Still fine'])->assertOk();
});
