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
    }
}
