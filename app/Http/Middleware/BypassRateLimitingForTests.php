<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to selectively bypass rate limiting in test environments based on headers
 */
class BypassRateLimitingForTests
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // If we're explicitly testing rate limits, don't bypass anything
        if ($request->header('X-Testing-Rate-Limits') === 'true') {
            return $next($request);
        }

        // Only skip rate limiting if we're in a test environment AND
        // the request has the X-Disable-Rate-Limiting header set to 'true'
        if (App::environment('testing', 'local', 'development')) {
            if ($request->header('X-Disable-Rate-Limiting') === 'true') {
                // For ThrottleRequests middleware, we set a flag that will disable rate limiting
                $request->attributes->set('throttle_middleware_disabled', true);

                // Add custom test headers to the response
                $response = $next($request);
                $response->headers->set('X-Rate-Limit-Bypassed', 'true');

                // Add mock rate limit headers for test consistency
                if (! $response->headers->has('X-RateLimit-Limit')) {
                    $response->headers->set('X-RateLimit-Limit', '1000');
                    $response->headers->set('X-RateLimit-Remaining', '999');
                }

                return $response;
            } elseif ($request->header('X-Enable-Rate-Limiting') === 'true') {
                // Explicitly enable rate limiting
                $request->attributes->set('throttle_middleware_disabled', false);
            }
        }

        return $next($request);
    }
}
