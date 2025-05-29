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

        // Spy on logs to verify proper logging
        Log::spy();
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
     * Helper method to make a POST request with proper webhook signature
     * and rate limiting bypass headers
     */
    protected function postJsonWithSignature(string $uri, array $data = []): TestResponse
    {
        return $this->postJson($uri, $data, array_merge(
            ['X-N8N-Signature' => $this->createWebhookSignature($data)],
            $this->getNoRateLimitHeaders()
        ));
    }
}
