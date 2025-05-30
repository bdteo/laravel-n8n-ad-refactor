<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to automatically disable rate limiting in test environments.
 */
class DisableRateLimitingForTests
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('testing')) {
            // Set the flag to disable rate limiting for tests
            $request->attributes->set('throttle_middleware_disabled', true);

            // Also set the standard headers for consistency
            $request->headers->set('X-Disable-Rate-Limiting', 'true');
            $request->headers->set('X-Enable-Rate-Limiting', 'false');
        }

        return $next($request);
    }
}
