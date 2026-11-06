<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\DomainError;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class EmailVerificationController extends Controller
{
    public function verify(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string']]);

        try {
            $payload = json_decode(Crypt::decryptString($data['token']), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw new DomainError('invalid_token', 'This verification link is invalid.');
        }

        if (! is_array($payload) || ! isset($payload['uid'], $payload['email'], $payload['exp'])) {
            throw new DomainError('invalid_token', 'This verification link is invalid.');
        }
        if ($payload['exp'] < now()->getTimestamp()) {
            throw new DomainError('token_expired', 'This verification link has expired.');
        }

        $user = User::find($payload['uid']);
        if (! $user || $user->email !== $payload['email']) {
            throw new DomainError('invalid_token', 'This verification link is invalid.');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return new UserResource($user);
    }

    public function resend(Request $request)
    {
        $user = $request->user();
        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json(['message' => 'Verification email sent.']);
    }
}
