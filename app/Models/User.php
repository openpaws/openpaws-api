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
     * Users without adult_confirmed_at get General; otherwise their chosen max_rating.
     */
    public function effectiveMaxRating(): Rating
    {
        if ($this->adult_confirmed_at === null) {
            return Rating::General;
        }

        return $this->max_rating ?? Rating::General;
    }

    public function profile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Profile::class);
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmail);
    }
}
