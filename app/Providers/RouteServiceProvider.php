<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        // General API rate limiting
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Ad script submission rate limiting - more restrictive since it triggers expensive operations
        RateLimiter::for('ad-script-submission', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Ad script submission hourly limit
        RateLimiter::for('ad-script-submission-hourly', function (Request $request) {
            return Limit::perHour(100)->by($request->ip());
        });

        // Result processing rate limiting - less restrictive since it's webhook callbacks
        RateLimiter::for('result-processing', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        // Result processing hourly limit
        RateLimiter::for('result-processing-hourly', function (Request $request) {
            return Limit::perHour(500)->by($request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
