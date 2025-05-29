<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to disable rate limiting in development/testing environments
 */
class DisableRateLimiting
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip rate limiting for API tests in development/testing environments
        if (App::environment(['local', 'development', 'testing'])) {
            // Pass a header to indicate rate limiting is disabled
            $request->headers->set('X-Rate-Limit-Disabled', 'true');

            // Process the request normally
            $response = $next($request);

            // Add a header to the response to indicate rate limiting was disabled
            $response->headers->set('X-Rate-Limit-Disabled', 'true');

            return $response;
        }

        return $next($request);
    }
}
