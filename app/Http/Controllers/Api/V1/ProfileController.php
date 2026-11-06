<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\ProfileResource;
use App\Models\Profile;

class ProfileController extends Controller
{
    public function update(UpdateProfileRequest $request)
    {
        $user = $request->user();
        $data = $request->validated();
        if (isset($data['links'])) {
            $data['links'] = array_map(fn ($l) => ['label' => $l['label'], 'url' => $l['url']], array_values($data['links']));
        }

        $profile = Profile::firstOrNew(['user_id' => $user->id]);
        $profile->user_id = $user->id;
        $profile->fill($data)->save();

        return (new ProfileResource($profile))->response()->setStatusCode(200);
    }
}
