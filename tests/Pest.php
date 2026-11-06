<?php

use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Unit');

/** An upload session owned by $user in the given kind and status (defaults to a completed image upload). */
function makeUploadSession(User $user, string $kind = 'image', string $status = 'uploaded'): UploadSession
{
    return UploadSession::create([
        'user_id' => $user->id, 'kind' => $kind, 'status' => $status, 'filename' => 'f.bin', 'mime' => 'image/png',
        'size' => 10, 'path' => 'staging/'.Str::uuid().'/f.bin', 'expires_at' => now()->addDay(),
    ]);
}

/** A user who has confirmed 18+ and chose the given max rating. */
function makeAdultUser(string $maxRating = 'adult'): User
{
    return User::factory()->create(['adult_confirmed_at' => now(), 'max_rating' => $maxRating]);
}
