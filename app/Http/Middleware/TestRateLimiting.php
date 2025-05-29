<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to handle rate limiting specifically for tests.
 *
 * This middleware simulates rate limiting behavior in tests without actually
 * using the real rate limiter, ensuring consistent and predictable test results.
 */
class TestRateLimiting
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only apply in testing environment and when the testing.rate_limiting flag is set
        if (! App::environment('testing') || app()->bound('testing.rate_limiting') === false) {
            return $next($request);
        }

        // If we're explicitly not testing rate limiting
        if (app()->make('testing.rate_limiting') === false) {
            $response = $next($request);

            // Add mock rate limit headers
            $response->headers->set('X-RateLimit-Limit', '1000');
            $response->headers->set('X-RateLimit-Remaining', '999');

            return $response;
        }

        // Get the rate limiter key from the request path
        $path = $request->path();
        $key = null;

        if (strpos($path, 'ad-scripts') !== false) {
            if (strpos($path, 'result') !== false) {
                $key = 'result-processing';
                $limit = 30;
            } else {
                $key = 'ad-script-submission';
                $limit = 10;
            }
        }

        // If no rate limiting should be applied, or we can't determine the key
        if ($request->header('X-Disable-Rate-Limiting') === 'true' || ! $key) {
            $response = $next($request);

            // Add mock rate limit headers
            $response->headers->set('X-RateLimit-Limit', '1000');
            $response->headers->set('X-RateLimit-Remaining', '999');

            return $response;
        }

        // If rate limiting is enabled
        if ($request->header('X-Enable-Rate-Limiting') === 'true') {
            // Get the IP address for per-IP rate limiting
            $ip = $request->server('REMOTE_ADDR', '127.0.0.1');
            $rateLimitKey = "{$key}:{$ip}";

            // Check if we should rate limit this request
            $count = RateLimiter::attempts($rateLimitKey);

            if ($count >= $limit) {
                // Rate limit exceeded
                $response = response()->json([
                    'error' => true,
                    'message' => 'Too Many Requests',
                    'type' => 'rate_limit_error',
                    'request_id' => uniqid('req_'),
                    'timestamp' => now()->toIso8601String(),
                ], 429);

                // Add rate limit headers
                $response->headers->set('X-RateLimit-Limit', (string)$limit);
                $response->headers->set('X-RateLimit-Remaining', '0');
                $response->headers->set('Retry-After', '60');

                return $response;
            }

            // Increment the rate limit counter
            RateLimiter::hit($rateLimitKey, 60);

            // Process the request
            $response = $next($request);

            // Add rate limit headers
            $response->headers->set('X-RateLimit-Limit', (string)$limit);
            $response->headers->set('X-RateLimit-Remaining', (string)($limit - $count - 1));

            return $response;
        }

        // Default behavior
        return $next($request);
    }
}
