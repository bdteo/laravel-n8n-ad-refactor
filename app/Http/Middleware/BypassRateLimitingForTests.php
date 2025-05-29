<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to bypass rate limiting in test environments
 */
class BypassRateLimitingForTests
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip rate limiting checks for tests
        if (App::environment('testing', 'local', 'development')) {
            // For ThrottleRequests middleware, we set a high number of requests allowed
            // This effectively disables rate limiting for testing
            $request->attributes->set('throttle_middleware_disabled', true);

            // Add custom test headers
            $response = $next($request);
            $response->headers->set('X-Rate-Limit-Bypassed', 'true');

            return $response;
        }

        return $next($request);
    }
}
