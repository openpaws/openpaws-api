<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProfileResource;
use App\Http\Resources\UserResource;
use App\Models\Profile;
use App\Models\User;

class UserController extends Controller
{
    public function show(string $username)
    {
        $user = User::where('username', strtolower($username))->firstOrFail();
        $profile = Profile::find($user->id) ?? new Profile(['links' => []]);

        return response()->json([
            'user' => (new UserResource($user))->resolve(request()),
            'profile' => (new ProfileResource($profile))->resolve(request()),
        ]);
    }
}
