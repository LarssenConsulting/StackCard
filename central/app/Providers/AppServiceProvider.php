<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        // Enrollment tokens are guessable only by brute force: keep it slow.
        RateLimiter::for('enroll', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('inventory', fn (Request $request) => Limit::perMinute(30)
            ->by($request->user()?->currentAccessToken()?->id ?: $request->ip()));

        // Enough to read every section of about 8 servers in a minute. Laravel authenticates before it
        // throttles, so a wrong token is not counted: a 40-character random token cannot be guessed anyway.
        RateLimiter::for('read-api', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
    }
}
