<?php

namespace Tests\Traits;

/**
 * Trait for testing rate limiting features.
 *
 * This trait provides methods to enable or disable rate limiting in tests,
 * and to create requests that explicitly enable or disable rate limiting.
 */
trait TestsRateLimiting
{
    /**
     * Enable rate limiting for tests.
     *
     * @param bool $enabled Whether to enable rate limiting
     * @return void
     */
    protected function setRateLimiting(bool $enabled = true): void
    {
        config(['services.n8n.disable_rate_limiting' => ! $enabled]);

        // Set the testing.rate_limiting flag that the middleware uses
        app()->singleton('testing.rate_limiting', function () use ($enabled) {
            return $enabled;
        });

        // Ensure config is correctly applied in application
        app()->instance('rate_limiting.status', ! $enabled);
    }

    /**
     * Get headers to enable rate limiting for a specific request.
     *
     * @return array
     */
    protected function getRateLimitedHeaders(): array
    {
        return ['X-Enable-Rate-Limiting' => 'true', 'X-Disable-Rate-Limiting' => 'false', 'X-Testing-Rate-Limits' => 'true'];
    }

    /**
     * Get headers to disable rate limiting for a specific request.
     *
     * @return array
     */
    protected function getNoRateLimitHeaders(): array
    {
        return [
            'X-Disable-Rate-Limiting' => 'true',
            'X-Enable-Rate-Limiting' => 'false',
            'throttle_middleware_disabled' => 'true',
            'X-Testing-Skip-Rate-Limits' => 'true',
        ];
    }

    /**
     * Run a test callback with rate limiting temporarily enabled.
     *
     * @param callable $callback The test callback to run
     * @return mixed The result of the callback
     */
    protected function withRateLimiting(callable $callback)
    {
        $originalConfig = config('services.n8n.disable_rate_limiting');
        $this->setRateLimiting(true);

        // Clear any existing rate limits before running tests
        $this->clearRateLimits();

        try {
            // Set a flag in the application to indicate we're testing rate limiting
            app()->instance('testing.rate_limiting', true);

            return $callback();
        } finally {
            config(['services.n8n.disable_rate_limiting' => $originalConfig]);
            app()->forgetInstance('testing.rate_limiting');
        }
    }

    /**
     * Run a test callback with rate limiting temporarily disabled.
     *
     * @param callable $callback The test callback to run
     * @return mixed The result of the callback
     */
    protected function withoutRateLimiting(callable $callback)
    {
        $originalConfig = config('services.n8n.disable_rate_limiting');
        $this->setRateLimiting(false);

        // Clear any existing rate limits before running tests
        $this->clearRateLimits();

        try {
            // Set a flag in the application to indicate we're not testing rate limiting
            app()->instance('testing.rate_limiting', false);

            return $callback();
        } finally {
            config(['services.n8n.disable_rate_limiting' => $originalConfig]);
            app()->forgetInstance('testing.rate_limiting');
        }
    }

    /**
     * Clear all rate limits used in tests.
     *
     * @return void
     */
    protected function clearRateLimits(): void
    {
        // Clear all rate limiters used in the application
        $limiters = [
            'ad-script-submission',
            'ad-script-submission-hourly',
            'result-processing',
            'result-processing-hourly',
            'api',
        ];

        // Use Laravel's cache system instead of direct Redis access
        // This works with any cache driver, not just Redis
        foreach ($limiters as $limiter) {
            $key = 'laravel_cache:limiter:' . $limiter . ':' . request()->ip();
            \Illuminate\Support\Facades\Cache::forget($key);
        }
    }
}
