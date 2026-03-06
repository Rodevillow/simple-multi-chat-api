<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Passport::enablePasswordGrant();
        Passport::tokensExpireIn(now()->addMinutes((int) env('OAUTH_ACCESS_TOKEN_TTL_MINUTES', 15)));
        Passport::refreshTokensExpireIn(now()->addDays((int) env('OAUTH_REFRESH_TOKEN_TTL_DAYS', 30)));

        ResetPassword::createUrlUsing(function (object $user, string $token): string {
            $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
            $email = urlencode((string) $user->getEmailForPasswordReset());

            return "{$frontendUrl}/reset-password?token={$token}&email={$email}";
        });
    }
}
