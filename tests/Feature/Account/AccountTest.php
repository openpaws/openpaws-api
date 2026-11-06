<?php

use App\Models\User;
use App\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

it('adult confirmation requires verified email', function () {
    $u = User::factory()->unverified()->create();
    $this->actingAs($u)->postJson('/api/v1/me/adult-confirmation', ['confirm' => true])
        ->assertForbidden()->assertJsonPath('error.code', 'forbidden');

    $v = User::factory()->create();
    $this->actingAs($v)->postJson('/api/v1/me/adult-confirmation', ['confirm' => true])
        ->assertOk()->assertJsonPath('data.adult_confirmed', true);
});

it('max_rating above general requires adult confirmation', function () {
    $u = User::factory()->create();
    $this->actingAs($u)->patchJson('/api/v1/me/settings', ['max_rating' => 'mature'])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['fields' => ['max_rating']]]);
    $this->actingAs($u)->postJson('/api/v1/me/adult-confirmation', ['confirm' => true])->assertOk();
    $this->actingAs($u)->patchJson('/api/v1/me/settings', ['max_rating' => 'mature'])
        ->assertOk()->assertJsonPath('data.max_rating', 'mature');
});

it('updates profile with links limited to 10 and urls only http(s)', function () {
    $u = User::factory()->create();
    $link = ['label' => 'Site', 'url' => 'https://example.com'];
    $this->actingAs($u)->patchJson('/api/v1/me/profile', [
        'bio' => '**hi**', 'species' => 'Fox', 'pronouns' => 'he/him', 'links' => [$link],
    ])->assertOk()->assertJsonPath('data.bio', '**hi**')->assertJsonPath('data.links.0.url', 'https://example.com')
        ->assertJsonPath('data.pinned', []);

    $this->actingAs($u)->patchJson('/api/v1/me/profile', ['links' => array_fill(0, 11, $link)])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    $this->actingAs($u)->patchJson('/api/v1/me/profile', ['links' => [['label' => 'x', 'url' => 'javascript:alert(1)']]])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    $this->actingAs($u)->patchJson('/api/v1/me/profile', ['links' => [['label' => 'x', 'url' => 'ftp://example.com']]])
        ->assertStatus(422);
});

it('changing email clears verification and resends', function () {
    Notification::fake();
    $u = User::factory()->create(['email' => 'old@example.com']);
    $this->actingAs($u)->patchJson('/api/v1/me', ['email' => 'new@example.com', 'display_name' => 'Newname'])
        ->assertOk()->assertJsonPath('data.email', 'new@example.com')->assertJsonPath('data.email_verified', false)
        ->assertJsonPath('data.display_name', 'Newname');
    expect($u->fresh()->email_verified_at)->toBeNull();
    Notification::assertSentTo($u->fresh(), VerifyEmail::class);
});

it('password change requires current password', function () {
    $u = User::factory()->create(['password' => 'old-password-1']);
    $this->actingAs($u)->patchJson('/api/v1/me/password', [
        'current_password' => 'wrong', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1',
    ])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['current_password']]]);

    $this->actingAs($u)->patchJson('/api/v1/me/password', [
        'current_password' => 'old-password-1', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1',
    ])->assertNoContent();
    expect(Hash::check('new-password-1', $u->fresh()->password))->toBeTrue();
    $this->getJson('/api/v1/me')->assertOk();
});

it('GET /users/{username} is case-insensitive and 404s for unknown users', function () {
    User::factory()->create(['username' => 'otter']);
    $this->getJson('/api/v1/users/OtTeR')->assertOk()
        ->assertJsonPath('user.username', 'otter')->assertJsonPath('profile.links', [])->assertJsonPath('profile.pinned', []);
    $this->getJson('/api/v1/users/nobody')->assertNotFound()->assertJsonPath('error.code', 'not_found');
});

it('bio_html is sanitised in GET /users/{username}', function () {
    $u = User::factory()->create(['username' => 'otter']);
    $this->actingAs($u)->patchJson('/api/v1/me/profile', ['bio' => "<script>x</script>\n\n[a](javascript:alert(1)) **ok**"])->assertOk();
    $r = $this->getJson('/api/v1/users/otter')->assertOk();
    expect($r->json('profile.bio_html'))->toContain('<strong>ok</strong>')->not->toContain('<script')->not->toContain('javascript:');
});
