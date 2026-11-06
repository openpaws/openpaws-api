<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'username' => $this->username,
            'display_name' => $this->display_name,
            'avatar_url' => null,
            'banner_url' => null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];

        if ($request->user() && $request->user()->is($this->resource)) {
            $data += [
                'email' => $this->email,
                'email_verified' => $this->email_verified_at !== null,
                'adult_confirmed' => $this->adult_confirmed_at !== null,
                'max_rating' => $this->max_rating->value,
                'role' => $this->role->value,
            ];
        }

        return $data;
    }
}
