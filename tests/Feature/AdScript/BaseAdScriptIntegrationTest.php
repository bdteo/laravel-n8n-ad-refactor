<?php

declare(strict_types=1);

namespace Tests\Feature\AdScript;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\LogManager;
use Tests\TestCase;
use Tests\Traits\TestsRateLimiting;

/**
 * Base class for all ad script integration tests.
 *
 * Contains common setup and helper methods used across all ad script test files.
 */
abstract class BaseAdScriptIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use TestsRateLimiting;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up test configuration
        config([
            'services.n8n.callback_hmac_secret' => 'integration-test-secret',
            'services.n8n.webhook_url' => 'https://test-n8n.example.com/webhook/test',
            'services.n8n.timeout' => 30,
            'services.n8n.retry_attempts' => 3,
        ]);

        // Disable rate limiting for all integration tests
        $this->setRateLimiting(false);

        // Signal to the application that we're running integration tests
        app()->instance('running_integration_test', true);

        // Mock the Log facade with a single instance to avoid redeclaration issues
        static $mockLoggerSet = false;
        if (! $mockLoggerSet) {
            $mockLogger = \Mockery::mock(LogManager::class);
            $mockLogger->shouldReceive('info')->andReturn(null);
            $mockLogger->shouldReceive('error')->andReturn(null);
            $mockLogger->shouldReceive('debug')->andReturn(null);
            $mockLogger->shouldReceive('warning')->andReturn(null);
            \Illuminate\Support\Facades\Log::swap($mockLogger);
            $mockLoggerSet = true;
        }
    }

    protected function tearDown(): void
    {
        // Clean up the integration test flag
        app()->forgetInstance('running_integration_test');

        parent::tearDown();
    }

    /**
     * Helper method to create a webhook signature for testing
     */
    protected function createWebhookSignature(array $data): string
    {
        $payload = json_encode($data);
        $secret = config('services.n8n.callback_hmac_secret');

        if (! is_string($secret) || $payload === false) {
            throw new \RuntimeException('Invalid webhook configuration for testing');
        }

        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }
}
