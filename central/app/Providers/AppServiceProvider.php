<?php

namespace App\Providers;

use App\Support\TrustedProxyList;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
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
        // Only named proxies are believed, and only for the address and scheme: never the Host header.
        if ($proxies = TrustedProxyList::parse(config('discover.trusted_proxies'))) {
            TrustProxies::at($proxies);
            TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);
        }

        // Enrollment tokens are guessable only by brute force: keep it slow.
        RateLimiter::for('enroll', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('inventory', fn (Request $request) => Limit::perMinute(30)
            ->by($request->user()?->currentAccessToken()?->id ?: $request->ip()));

        // Enough to read every section of about 8 servers in a minute. Laravel authenticates before it
        // throttles, so a wrong token is not counted: a 40-character random token cannot be guessed anyway.
        RateLimiter::for('read-api', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
    }
}
