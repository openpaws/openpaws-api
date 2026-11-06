<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;

class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request)
    {
        $data = $request->validated();

        $user = User::create([
            'username' => $data['username'],
            'display_name' => $data['display_name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        $user->profile()->create();

        $user = $user->fresh();
        event(new Registered($user)); // sends the verification email

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return (new UserResource($user))->response()->setStatusCode(201);
    }
}
