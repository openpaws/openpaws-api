<?php

namespace App\Http\Resources;

use App\Enums\Rating;
use App\Enums\UserRole;
use App\Models\User;
use App\Storage\ObjectStorage;
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
            'avatar_url' => $this->avatar_path ? app(ObjectStorage::class)->publicUrl($this->avatar_path) : null,
            'banner_url' => $this->banner_path ? app(ObjectStorage::class)->publicUrl($this->banner_path) : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];

        if ($request->user() && $request->user()->is($this->resource)) {
            $data += [
                'email' => $this->email,
                'email_verified' => $this->email_verified_at !== null,
                'adult_confirmed' => $this->adult_confirmed_at !== null,
                'max_rating' => ($this->max_rating ?? Rating::General)->value,
                'role' => ($this->role ?? UserRole::User)->value,
            ];
        }

        return $data;
    }
}
