<?php

namespace App\Models;

use App\Enums\Rating;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Notifications\VerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['username', 'display_name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'adult_confirmed_at' => 'datetime',
            'password' => 'hashed',
            'max_rating' => Rating::class,
            'role' => UserRole::class,
            'status' => UserStatus::class,
        ];
    }

    /**
     * The user's chosen max rating, capped at Mature until adulthood is confirmed.
     */
    public function effectiveMaxRating(): Rating
    {
        $max = $this->max_rating ?? Rating::General;

        if ($max === Rating::Adult && $this->adult_confirmed_at === null) {
            return Rating::Mature;
        }

        return $max;
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmail);
    }
}
