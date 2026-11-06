<?php

use App\Models\User;

it('verifies a user email', function () {
    $user = User::factory()->unverified()->create();

    $this->artisan('openpaws:test-verify', ['username' => $user->username])->assertSuccessful();

    $user->refresh();
    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->adult_confirmed_at)->toBeNull();
});

it('optionally confirms adulthood', function () {
    $user = User::factory()->unverified()->create();

    $this->artisan('openpaws:test-verify', ['username' => $user->username, '--adult' => true])->assertSuccessful();

    expect($user->refresh()->adult_confirmed_at)->not->toBeNull();
});

it('fails for an unknown user', function () {
    $this->artisan('openpaws:test-verify', ['username' => 'nobody_here'])->assertFailed();
});
