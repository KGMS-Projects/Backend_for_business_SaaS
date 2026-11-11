<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // General API limit: per authenticated user, or per IP for anonymous calls.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(config('service_requests.rate_limit.api'))
            ->by($request->user('sanctum')?->getAuthIdentifier() ?: $request->ip()));

        // Login: per e-mail + IP, to slow down password guessing.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(config('service_requests.rate_limit.login'))
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
    }
}
