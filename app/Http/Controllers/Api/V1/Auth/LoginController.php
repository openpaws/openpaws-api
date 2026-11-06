<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $field = str_contains($data['login'], '@') ? 'email' : 'username';
        $value = $field === 'username' ? strtolower($data['login']) : $data['login'];

        if (! Auth::guard('web')->attempt(
            [$field => $value, 'password' => $data['password']],
            (bool) ($data['remember'] ?? false),
        )) {
            throw ValidationException::withMessages(['login' => ['These credentials do not match our records.']]);
        }

        /** @var User $user */
        $user = Auth::guard('web')->user();
        if ($user->status !== UserStatus::Active) {
            Auth::guard('web')->logout();
            throw ValidationException::withMessages(['login' => ['This account is not active.']]);
        }

        $request->session()->regenerate();

        return new UserResource($user);
    }
}
