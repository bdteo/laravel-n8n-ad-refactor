<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to handle rate limiting with optional bypass capabilities.
 *
 * This middleware provides several ways to control rate limiting:
 * 1. Global config setting services.n8n.disable_rate_limiting (for environments)
 * 2. HTTP header X-Enable-Rate-Limiting that overrides the config and forces rate limiting
 * 3. HTTP header X-Disable-Rate-Limiting that overrides the config and bypasses rate limiting
 *
 * The priority order is: headers > config > default (rate limiting enabled)
 */
class NoThrottleRequests extends ThrottleRequests
{
    /**
     * Handle an incoming request with conditional rate limiting.
     */
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = ''): Response
    {
        // For testing environment, we need to handle rate limiting differently
        if (app()->environment('testing') && $request->header('X-Testing-Rate-Limits') === 'true') {
            // Force the standard throttle behavior for tests
            return parent::handle($request, $next, $maxAttempts, $decayMinutes, $prefix);
        }

        // Check if rate limiting should be bypassed based on headers or config
        if ($this->shouldBypassRateLimiting($request)) {
            $response = $next($request);

            // Add headers to indicate rate limiting was bypassed
            $response->headers->set('X-RateLimit-Bypassed', 'true');

            // Add mock rate limit headers for test consistency
            if (! $response->headers->has('X-RateLimit-Limit')) {
                $response->headers->set('X-RateLimit-Limit', (string)$maxAttempts);
                $response->headers->set('X-RateLimit-Remaining', (string)($maxAttempts - 1));
            }

            return $response;
        }

        // Apply normal rate limiting
        $response = parent::handle($request, $next, $maxAttempts, $decayMinutes, $prefix);

        // Ensure rate limit headers are present
        if (! $response->headers->has('X-RateLimit-Limit')) {
            $limiter = $this->limiter;
            $key = $this->resolveRequestSignature($request);

            $response->headers->set('X-RateLimit-Limit', (string)$maxAttempts);
            $response->headers->set('X-RateLimit-Remaining', (string)$limiter->remaining($key, $maxAttempts));
        }

        return $response;
    }

    /**
     * Determine if rate limiting should be bypassed for this request.
     */
    protected function shouldBypassRateLimiting(Request $request): bool
    {
        // Check for attribute set by BypassRateLimitingForTests middleware
        if ($request->attributes->get('throttle_middleware_disabled', false) === true) {
            return true;
        }

        // First check headers - they override config settings
        if ($request->header('X-Enable-Rate-Limiting') === 'true') {
            // Explicit request to enable rate limiting via header
            return false;
        }

        if ($request->header('X-Disable-Rate-Limiting') === 'true') {
            // Explicit request to disable rate limiting via header
            return true;
        }

        // Fall back to config setting
        return config('services.n8n.disable_rate_limiting', false);
    }
}
