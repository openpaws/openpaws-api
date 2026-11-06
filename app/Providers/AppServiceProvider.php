<?php

namespace App\Providers;

use App\Models\Submission;
use App\Models\User;
use App\Search\TagAwareCollectionEngine;
use App\Storage\ObjectStorage;
use App\Storage\S3ObjectStorage;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ObjectStorage::class, S3ObjectStorage::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->make(EngineManager::class)->extend('collection', fn () => new TagAwareCollectionEngine);

        Relation::morphMap(['submission' => Submission::class, 'user' => User::class]);

        // Every single-submission route resolves through visibleTo: not visible => 404.
        Route::bind('submission', fn (string $value) => Submission::visibleTo(auth('sanctum')->user())
            ->where('public_id', $value)->firstOrFail());

        ResetPassword::createUrlUsing(fn ($user, string $token) => rtrim(config('openpaws.ui_url'), '/')
            .'/reset-password?token='.urlencode($token).'&email='.urlencode($user->getEmailForPasswordReset()));

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by($request->ip().'|'.strtolower((string) $request->input('login'))));
        RateLimiter::for('register', fn (Request $request) => Limit::perHour(3)->by($request->ip()));
        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perHour(30)->by($request->user()?->id ?: $request->ip()));

        // Endpoints that send mail (R33), so nobody can use the platform to spam an address.
        RateLimiter::for('email-resend', fn (Request $request) => Limit::perMinutes(10, 3)
            ->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('password-forgot', fn (Request $request) => [
            Limit::perHour(5)->by('ip:'.$request->ip()),
            Limit::perHour(3)->by('email:'.strtolower(trim((string) $request->input('email')))),
        ]);
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perHour(10)->by($request->ip()));
        // PATCH /me sends a verification mail to the new address, so only email changes count.
        RateLimiter::for('email-change', function (Request $request) {
            $user = $request->user();
            $changesEmail = $user !== null && $request->has('email') && (string) $request->input('email') !== $user->email;

            return $changesEmail ? Limit::perHour(5)->by($user->id) : Limit::none();
        });
    }
}
