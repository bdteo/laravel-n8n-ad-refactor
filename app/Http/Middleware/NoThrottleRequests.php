<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Extension of ThrottleRequests middleware that disables rate limiting
 * completely in development/testing environments
 */
class NoThrottleRequests extends ThrottleRequests
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(RateLimiter $limiter)
    {
        parent::__construct($limiter);
    }

    /**
     * Handle an incoming request.
     */
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = ''): Response
    {
        // Skip rate limiting completely in development/testing environments
        if (App::environment(['local', 'development', 'testing'])) {
            return $next($request);
        }

        // Call the parent's handle method for production environments
        return parent::handle($request, $next, $maxAttempts, $decayMinutes, $prefix);
    }
}
