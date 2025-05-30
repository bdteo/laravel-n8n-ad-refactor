<?php

declare(strict_types=1);

namespace Tests\Feature\AdScript;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\TestsRateLimiting;

/**
 * Base class for ad script workflow tests.
 *
 * Contains common setup and helper methods used across all ad script workflow test classes.
 */
abstract class BaseAdScriptWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use TestsRateLimiting;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up test configuration
        config([
            'services.n8n.callback_hmac_secret' => 'test-webhook-secret',
            'services.n8n.webhook_url' => 'https://test-n8n.example.com/webhook/test',
            'services.n8n.timeout' => 30,
            'services.n8n.retry_attempts' => 3,
        ]);

        // Signal to the application that we're running integration tests
        app()->instance('running_integration_test', true);

        // Spy on logs to verify proper logging
        Log::spy();
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

    /**
     * Helper method to post JSON with a valid webhook signature
     *
     * @param string $uri The URI to post to
     * @param array $data The data to send (will be JSON encoded)
     * @param array $headers Additional headers to include
     * @param string|null $rawContent Raw content to send instead of JSON-encoded $data
     * @return TestResponse
     */
    protected function postJsonWithSignature(string $uri, array $data = [], array $headers = [], ?string $rawContent = null): TestResponse
    {
        $content = $rawContent ?? json_encode($data);
        $signature = 'sha256=' . hash_hmac('sha256', $content, config('services.n8n.callback_hmac_secret'));

        $headers = array_merge(
            [
                'X-N8N-Signature' => $signature,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            $headers,
            $this->getNoRateLimitHeaders()
        );

        if ($rawContent !== null) {
            return $this->call('POST', $uri, [], [], [], $this->transformHeadersToServerVars($headers), $rawContent);
        }

        return $this->postJson($uri, $data, $headers);
    }

    /**
     * Execute a code block with rate limiting disabled
     */
    protected function withoutRateLimiting(callable $callback)
    {
        // Temporarily disable rate limiting
        $this->setRateLimiting(false);

        try {
            return $callback();
        } finally {
            // Set rate limiting back to enabled
            $this->setRateLimiting(true);
        }
    }
}
