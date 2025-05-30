<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to bypass rate limiting specifically for integration tests.
 *
 * This middleware ensures that requests from integration tests are not
 * subject to rate limiting, allowing tests to run consistently and reliably.
 */
class IntegrationTestRateLimitBypass
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if we're in a test environment and running integration tests
        if (app()->environment('testing') && $this->isIntegrationTest()) {
            // Set attribute that CustomThrottleRequests will check to bypass rate limiting
            $request->attributes->set('throttle_middleware_disabled', true);

            // Add the headers that bypass rate limiting for consistency
            $request->headers->set('X-Disable-Rate-Limiting', 'true');
            $request->headers->set('X-Enable-Rate-Limiting', 'false');
            $request->headers->set('X-Testing-Skip-Rate-Limits', 'true');

            // Process the request
            $response = $next($request);

            // Add headers to the response for debugging purposes
            $response->headers->set('X-Rate-Limit-Bypassed-For-Test', 'true');

            // Add mock rate limit headers for test consistency
            if (! $response->headers->has('X-RateLimit-Limit')) {
                $response->headers->set('X-RateLimit-Limit', '1000');
                $response->headers->set('X-RateLimit-Remaining', '999');
            }

            return $response;
        }

        return $next($request);
    }

    /**
     * Determine if the current request is an integration test.
     *
     * @return bool
     */
    private function isIntegrationTest(): bool
    {
        // Check for integration test environment variable or config setting
        return config('services.n8n.integration_test_mode', false) ||
               env('INTEGRATION_TEST_MODE', false) ||
               // Check for common test headers
               request()->hasHeader('X-Integration-Test') ||
               request()->hasHeader('X-Testing-Environment');
    }
}
