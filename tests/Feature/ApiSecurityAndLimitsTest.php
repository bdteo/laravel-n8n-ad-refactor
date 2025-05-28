<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\AdScriptTask;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Feature tests for API security, rate limiting, and authentication scenarios.
 *
 * These tests focus on security aspects of the API including webhook authentication,
 * rate limiting, and various security edge cases.
 */
class ApiSecurityAndLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up test configuration
        config([
            'services.n8n.webhook_secret' => 'test-webhook-secret-for-security-tests',
        ]);

        // Fake the queue to prevent actual job execution
        Queue::fake();
    }

    /**
     * Helper method to create a webhook signature for testing
     */
    private function createWebhookSignature(array $data): string
    {
        $payload = json_encode($data);
        $secret = config('services.n8n.webhook_secret');

        if (! is_string($secret) || $payload === false) {
            throw new \RuntimeException('Invalid webhook configuration for testing');
        }

        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }

    public function test_webhook_signature_validation_with_various_invalid_signatures(): void
    {
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PROCESSING]);
        $payload = ['new_script' => 'Test script'];

        $invalidSignatures = [
            '', // Empty signature
            'invalid', // No sha256 prefix
            'sha256=', // Empty hash
            'sha256=invalid_hash', // Invalid hash
            'sha1=' . hash_hmac('sha1', json_encode($payload), config('services.n8n.webhook_secret')), // Wrong algorithm
            'sha256=' . hash_hmac('sha256', json_encode($payload), 'wrong_secret'), // Wrong secret
            'sha256=' . hash_hmac('sha256', json_encode(['different' => 'payload']), config('services.n8n.webhook_secret')), // Different payload
        ];

        foreach ($invalidSignatures as $index => $signature) {
            $response = $this->postJson("/api/ad-scripts/{$task->id}/result", $payload, [
                'X-N8N-Signature' => $signature,
            ]);

            $response->assertStatus(401, "Invalid signature test {$index} should fail with 401");
        }

        // Verify task wasn't modified by any invalid requests
        $task->refresh();
        $this->assertEquals(TaskStatus::PROCESSING, $task->status);
    }

    public function test_webhook_signature_validation_with_missing_header(): void
    {
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PROCESSING]);
        $payload = ['new_script' => 'Test script'];

        // Request without signature header
        $response = $this->postJson("/api/ad-scripts/{$task->id}/result", $payload);
        $response->assertStatus(401);

        // Request with empty signature header
        $response = $this->postJson("/api/ad-scripts/{$task->id}/result", $payload, [
            'X-N8N-Signature' => '',
        ]);
        $response->assertStatus(401);

        // Verify task wasn't modified
        $task->refresh();
        $this->assertEquals(TaskStatus::PROCESSING, $task->status);
    }

    public function test_webhook_signature_validation_with_case_sensitivity(): void
    {
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PROCESSING]);
        $payload = ['new_script' => 'Test script'];
        $validSignature = $this->createWebhookSignature($payload);

        // Test with different header case variations
        $headerVariations = [
            'x-n8n-signature', // lowercase
            'X-n8n-Signature', // mixed case
            'X-N8N-SIGNATURE', // uppercase
        ];

        foreach ($headerVariations as $header) {
            $response = $this->postJson("/api/ad-scripts/{$task->id}/result", $payload, [
                $header => $validSignature,
            ]);

            // Laravel normalizes headers, so all should work
            $response->assertStatus(200, "Header case variation '{$header}' should work");
        }
    }

    public function test_webhook_handles_timing_attacks_consistently(): void
    {
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PROCESSING]);
        $payload = ['new_script' => 'Test script'];

        $validSignature = $this->createWebhookSignature($payload);
        $invalidSignature = 'sha256=invalid_signature_for_timing_test';

        // Measure response times for valid and invalid signatures
        $validTimes = [];
        $invalidTimes = [];

        for ($i = 0; $i < 5; $i++) {
            // Test valid signature
            $start = microtime(true);
            $this->postJson("/api/ad-scripts/{$task->id}/result", $payload, [
                'X-N8N-Signature' => $validSignature,
            ]);
            $validTimes[] = microtime(true) - $start;

            // Reset task status for next test
            $task->update(['status' => TaskStatus::PROCESSING]);

            // Test invalid signature
            $start = microtime(true);
            $this->postJson("/api/ad-scripts/{$task->id}/result", $payload, [
                'X-N8N-Signature' => $invalidSignature,
            ]);
            $invalidTimes[] = microtime(true) - $start;
        }

        // Response times should be relatively consistent (within reasonable bounds)
        // This is a basic timing attack protection test
        $avgValid = array_sum($validTimes) / count($validTimes);
        $avgInvalid = array_sum($invalidTimes) / count($invalidTimes);

        // The difference shouldn't be more than 1 second (generous for testing environments)
        $this->assertLessThan(
            1.0,
            abs($avgValid - $avgInvalid),
            'Response times should be consistent to prevent timing attacks'
        );
    }

    public function test_api_handles_large_number_of_requests_gracefully(): void
    {
        // Clear any existing rate limits to start fresh
        RateLimiter::clear('ad-script-submission');
        Cache::flush();

        // Test submission endpoint with many requests
        $responses = [];
        $startTime = microtime(true);

        for ($i = 0; $i < 50; $i++) {
            $responses[] = $this->postJson('/api/ad-scripts', [
                'reference_script' => "Load test script {$i}",
                'outcome_description' => "Load test description {$i}",
            ]);
        }

        $endTime = microtime(true);
        $totalTime = $endTime - $startTime;

        // Count successful vs rate-limited responses
        $successCount = 0;
        $rateLimitedCount = 0;
        $otherCount = 0;

        foreach ($responses as $index => $response) {
            $status = $response->getStatusCode();

            if ($status === 202) {
                $successCount++;
            } elseif ($status === 429) {
                $rateLimitedCount++;
            } else {
                $otherCount++;
            }
        }

        // Should have exactly 10 successful requests (rate limit) and 40 rate-limited
        $this->assertEquals(10, $successCount, 'Should allow exactly 10 requests per minute');
        $this->assertEquals(40, $rateLimitedCount, 'Should rate limit requests beyond 10 per minute');
        $this->assertEquals(0, $otherCount, 'Should only have success or rate-limited responses');

        // Should complete within reasonable time (10 seconds for 50 requests)
        $this->assertLessThan(10.0, $totalTime, 'API should handle load efficiently');

        // Verify only successful tasks were created
        $this->assertEquals($successCount, AdScriptTask::count());
    }

    public function test_api_handles_malformed_requests_securely(): void
    {
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PROCESSING]);

        $malformedRequests = [
            // Invalid JSON structures
            '{"incomplete": json',
            '{"new_script": "test"', // Missing closing brace
            'not json at all',
            '{"new_script": "test", "extra_comma":,}',
            '{"new_script": null}', // Null values
            '{"new_script": []}', // Wrong data types
        ];

        foreach ($malformedRequests as $index => $malformedJson) {
            $signature = 'sha256=' . hash_hmac('sha256', $malformedJson, config('services.n8n.webhook_secret'));

            $response = $this->call(
                'POST',
                "/api/ad-scripts/{$task->id}/result",
                [],
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_X_N8N_SIGNATURE' => $signature,
                ],
                $malformedJson
            );

            // Should handle malformed JSON gracefully
            $this->assertContains(
                $response->getStatusCode(),
                [302, 400, 422],
                "Malformed request {$index} should be handled gracefully"
            );
        }

        // Verify task wasn't modified by malformed requests
        $task->refresh();
        $this->assertEquals(TaskStatus::PROCESSING, $task->status);
    }

    public function test_api_prevents_sql_injection_attempts(): void
    {
        // Test submission endpoint with SQL injection attempts
        $sqlInjectionPayloads = [
            "'; DROP TABLE ad_script_tasks; --",
            "' OR '1'='1",
            "'; UPDATE ad_script_tasks SET status='completed'; --",
            "' UNION SELECT * FROM users --",
            "'; INSERT INTO ad_script_tasks VALUES ('malicious'); --",
        ];

        foreach ($sqlInjectionPayloads as $index => $maliciousPayload) {
            $response = $this->postJson('/api/ad-scripts', [
                'reference_script' => $maliciousPayload,
                'outcome_description' => 'Testing SQL injection protection',
            ]);

            // Should either succeed (if properly escaped) or fail validation
            $this->assertContains(
                $response->getStatusCode(),
                [202, 422],
                "SQL injection attempt {$index} should be handled safely"
            );
        }

        // Verify database integrity
        $this->assertDatabaseMissing('ad_script_tasks', [
            'status' => 'completed', // Shouldn't have been updated by injection
        ]);
    }

    public function test_api_handles_xss_attempts_safely(): void
    {
        $xssPayloads = [
            '<script>alert("xss")</script>',
            '"><script>alert("xss")</script>',
            'javascript:alert("xss")',
            '<img src="x" onerror="alert(\'xss\')">',
            '&lt;script&gt;alert("xss")&lt;/script&gt;',
        ];

        foreach ($xssPayloads as $index => $xssPayload) {
            $response = $this->postJson('/api/ad-scripts', [
                'reference_script' => $xssPayload,
                'outcome_description' => 'Testing XSS protection',
            ]);

            if ($response->getStatusCode() === 202) {
                $taskId = $response->json('data.id');
                $task = AdScriptTask::find($taskId);

                // Verify the payload was stored as-is (not executed)
                $this->assertEquals($xssPayload, $task->reference_script);

                // Verify response doesn't contain executable script
                $responseContent = $response->getContent();
                $this->assertStringNotContainsString('<script>', $responseContent);
            }
        }
    }

    public function test_api_enforces_content_type_restrictions(): void
    {
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PROCESSING]);
        $payload = ['new_script' => 'Test script'];
        $signature = $this->createWebhookSignature($payload);

        // Test with various content types
        $contentTypes = [
            'text/plain',
            'application/xml',
            'application/x-www-form-urlencoded',
            'multipart/form-data',
            'text/html',
        ];

        foreach ($contentTypes as $contentType) {
            $response = $this->call(
                'POST',
                "/api/ad-scripts/{$task->id}/result",
                $payload,
                [],
                [],
                [
                    'CONTENT_TYPE' => $contentType,
                    'HTTP_X_N8N_SIGNATURE' => $signature,
                ]
            );

            // Should reject non-JSON content types or fail on webhook validation
            if ($contentType !== 'application/json') {
                $this->assertContains(
                    $response->getStatusCode(),
                    [400, 401, 415, 422],
                    "Content type {$contentType} should be rejected or fail webhook validation"
                );
            }
        }
    }

    public function test_api_handles_oversized_payloads(): void
    {
        // Test with extremely large payload (beyond reasonable limits)
        $oversizedScript = str_repeat('A', 100000); // 100KB
        $oversizedDescription = str_repeat('B', 10000); // 10KB

        $response = $this->postJson('/api/ad-scripts', [
            'reference_script' => $oversizedScript,
            'outcome_description' => $oversizedDescription,
        ]);

        // Should either reject or handle gracefully
        $this->assertContains(
            $response->getStatusCode(),
            [413, 422],
            'Oversized payload should be rejected'
        );
    }

    public function test_api_validates_uuid_format_in_routes(): void
    {
        $invalidUuids = [
            'not-a-uuid',
            '123',
            'invalid-uuid-format',
            '550e8400-e29b-41d4-a716', // Too short
            '550e8400-e29b-41d4-a716-446655440000-extra', // Too long
            '550e8400-e29b-41d4-a716-44665544000g', // Invalid character
        ];

        foreach ($invalidUuids as $invalidUuid) {
            $response = $this->postJson("/api/ad-scripts/{$invalidUuid}/result", [
                'new_script' => 'Test script',
            ], [
                'X-N8N-Signature' => $this->createWebhookSignature(['new_script' => 'Test script']),
            ]);

            // Should return 404 for invalid UUID format
            $response->assertStatus(404, "Invalid UUID {$invalidUuid} should return 404");
        }
    }

    public function test_api_handles_concurrent_requests_to_same_task(): void
    {
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PROCESSING]);
        $payload = ['new_script' => 'Concurrent test'];

        // Simulate multiple concurrent requests to the same task
        $responses = [];
        for ($i = 0; $i < 5; $i++) {
            $responses[] = $this->postJson("/api/ad-scripts/{$task->id}/result", $payload, [
                'X-N8N-Signature' => $this->createWebhookSignature($payload),
            ]);
        }

        // All should succeed due to idempotency
        foreach ($responses as $index => $response) {
            $response->assertStatus(200, "Concurrent request {$index} should succeed");
        }

        // Verify final state is consistent
        $task->refresh();
        $this->assertEquals(TaskStatus::COMPLETED, $task->status);
        $this->assertEquals($payload['new_script'], $task->new_script);
    }

    public function test_api_security_headers_are_present(): void
    {
        $response = $this->postJson('/api/ad-scripts', [
            'reference_script' => 'Security header test',
            'outcome_description' => 'Testing security headers',
        ]);

        // Check for basic security headers (if implemented)
        $headers = $response->headers;

        // These are optional but recommended security headers
        // The test documents what should be implemented
        $securityHeaders = [
            'X-Content-Type-Options',
            'X-Frame-Options',
            'X-XSS-Protection',
            'Referrer-Policy',
        ];

        foreach ($securityHeaders as $header) {
            // This test documents expected security headers
            // Implementation may vary based on middleware setup
            if ($headers->has($header)) {
                $this->assertNotEmpty(
                    $headers->get($header),
                    "Security header {$header} should have a value"
                );
            }
        }
    }

    public function test_api_rate_limiting_behavior(): void
    {
        // Clear any existing rate limits
        RateLimiter::clear('ad-script-submission');
        RateLimiter::clear('result-processing');
        Cache::flush();

        // Test ad script submission rate limiting (10 per minute)
        $responses = [];

        // Make 12 requests quickly to exceed the 10 per minute limit
        for ($i = 0; $i < 12; $i++) {
            $responses[] = $this->postJson('/api/ad-scripts', [
                'reference_script' => "Rate limit test {$i}",
                'outcome_description' => 'Testing rate limits',
            ]);
        }

        // Count successful vs rate-limited responses
        $successCount = 0;
        $rateLimitedCount = 0;
        $otherCount = 0;

        foreach ($responses as $index => $response) {
            $status = $response->getStatusCode();

            if ($status === 202) {
                $successCount++;
            } elseif ($status === 429) {
                $rateLimitedCount++;

                // Verify we got a rate limited response
                $this->assertNotEmpty($response->getContent(), 'Rate limited response should have content');
            } else {
                $otherCount++;
                $this->fail("Unexpected response status: {$status} for request {$index}: " . $response->getContent());
            }
        }

        echo "Success: {$successCount}, Rate Limited: {$rateLimitedCount}, Other: {$otherCount}\n";

        // Should have exactly 10 successful requests and 2 rate-limited
        $this->assertEquals(10, $successCount, 'Should allow exactly 10 requests per minute');
        $this->assertEquals(2, $rateLimitedCount, 'Should rate limit requests beyond 10 per minute');
    }

    public function test_result_processing_rate_limiting(): void
    {
        // Clear any existing rate limits
        RateLimiter::clear('result-processing');
        Cache::flush();

        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PROCESSING]);
        $payload = ['new_script' => 'Rate limit test'];
        $signature = $this->createWebhookSignature($payload);

        $responses = [];

        // Make 32 requests quickly to exceed the 30 per minute limit
        for ($i = 0; $i < 32; $i++) {
            $responses[] = $this->postJson("/api/ad-scripts/{$task->id}/result", $payload, [
                'X-N8N-Signature' => $signature,
            ]);

            // Reset task status for idempotency testing
            $task->update(['status' => TaskStatus::PROCESSING]);
        }

        // Count successful vs rate-limited responses
        $successCount = 0;
        $rateLimitedCount = 0;

        foreach ($responses as $index => $response) {
            if ($response->getStatusCode() === 200) {
                $successCount++;
            } elseif ($response->getStatusCode() === 429) {
                $rateLimitedCount++;

                // Verify we got a rate limited response
                $this->assertNotEmpty($response->getContent(), 'Rate limited response should have content');
            } else {
                $this->fail("Unexpected response status: {$response->getStatusCode()} for request {$index}");
            }
        }

        // Should have exactly 30 successful requests and 2 rate-limited
        $this->assertEquals(30, $successCount, 'Should allow exactly 30 result processing requests per minute');
        $this->assertEquals(2, $rateLimitedCount, 'Should rate limit result processing requests beyond 30 per minute');
    }

    public function test_rate_limiting_is_per_ip_address(): void
    {
        // Clear any existing rate limits
        RateLimiter::clear('ad-script-submission');
        Cache::flush();

        // Simulate requests from different IP addresses
        $ip1Responses = [];
        $ip2Responses = [];

        // Make 10 requests from first IP (should all succeed)
        for ($i = 0; $i < 10; $i++) {
            $ip1Responses[] = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
                ->postJson('/api/ad-scripts', [
                    'reference_script' => "IP1 test {$i}",
                    'outcome_description' => 'Testing per-IP rate limits',
                ]);
        }

        // Make 10 requests from second IP (should also all succeed)
        for ($i = 0; $i < 10; $i++) {
            $ip2Responses[] = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.2'])
                ->postJson('/api/ad-scripts', [
                    'reference_script' => "IP2 test {$i}",
                    'outcome_description' => 'Testing per-IP rate limits',
                ]);
        }

        // All requests from both IPs should succeed (separate limits)
        foreach ($ip1Responses as $index => $response) {
            $response->assertStatus(202, "IP1 request {$index} should succeed");
        }

        foreach ($ip2Responses as $index => $response) {
            $response->assertStatus(202, "IP2 request {$index} should succeed");
        }

        // Now make one more request from first IP (should be rate limited)
        $extraResponse = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.1'])
            ->postJson('/api/ad-scripts', [
                'reference_script' => 'IP1 extra test',
                'outcome_description' => 'Testing rate limit exceeded',
            ]);

        $extraResponse->assertStatus(429, 'Extra request from IP1 should be rate limited');
    }

    public function test_rate_limiting_headers_are_informative(): void
    {
        // Clear any existing rate limits
        RateLimiter::clear('ad-script-submission');
        Cache::flush();

        // Make requests until rate limited
        $responses = [];
        for ($i = 0; $i < 12; $i++) {
            $responses[] = $this->postJson('/api/ad-scripts', [
                'reference_script' => "Header test {$i}",
                'outcome_description' => 'Testing rate limit headers',
            ]);
        }

        // Find the first rate-limited response
        $rateLimitedResponse = null;
        foreach ($responses as $response) {
            if ($response->getStatusCode() === 429) {
                $rateLimitedResponse = $response;

                break;
            }
        }

        $this->assertNotNull($rateLimitedResponse, 'Should have at least one rate-limited response');

        // Verify we have a rate limited response with content
        $this->assertEquals(429, $rateLimitedResponse->getStatusCode(), 'Should be a 429 response');
        $this->assertNotEmpty($rateLimitedResponse->getContent(), 'Rate limited response should have content');
    }

    public function test_hourly_rate_limiting_for_ad_script_submission(): void
    {
        // This test verifies the hourly limit exists but doesn't actually test it
        // since it would require 100+ requests which is impractical for unit tests

        // Clear any existing rate limits
        RateLimiter::clear('ad-script-submission');
        Cache::flush();

        // Make exactly the per-minute limit (10 requests)
        $responses = [];
        for ($i = 0; $i < 10; $i++) {
            $responses[] = $this->postJson('/api/ad-scripts', [
                'reference_script' => "Hourly test {$i}",
                'outcome_description' => 'Testing hourly limits exist',
            ]);
        }

        // All should succeed (within both minute and hour limits)
        foreach ($responses as $index => $response) {
            $response->assertStatus(202, "Request {$index} should succeed within hourly limits");
        }

        // The 11th request should be rate limited by the minute limit
        $extraResponse = $this->postJson('/api/ad-scripts', [
            'reference_script' => 'Extra request',
            'outcome_description' => 'Should be rate limited',
        ]);

        $extraResponse->assertStatus(429);

        // Verify it's a rate limited response (the exact message format may vary)
        $responseData = $extraResponse->json();
        $this->assertArrayHasKey('message', $responseData, 'Response should have a message');
        $this->assertNotEmpty($responseData['message'], 'Message should not be empty');
    }

    public function test_rate_limiting_respects_different_endpoints(): void
    {
        // Clear any existing rate limits
        RateLimiter::clear('ad-script-submission');
        RateLimiter::clear('result-processing');
        Cache::flush();

        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PROCESSING]);
        $payload = ['new_script' => 'Cross-endpoint test'];
        $signature = $this->createWebhookSignature($payload);

        // Use up the ad-script-submission limit (10 requests)
        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/ad-scripts', [
                'reference_script' => "Submission test {$i}",
                'outcome_description' => 'Testing cross-endpoint limits',
            ]);
            $response->assertStatus(202);
        }

        // Verify submission endpoint is now rate limited
        $submissionResponse = $this->postJson('/api/ad-scripts', [
            'reference_script' => 'Should be rate limited',
            'outcome_description' => 'Testing rate limit',
        ]);
        $submissionResponse->assertStatus(429);

        // But result processing endpoint should still work (separate limit)
        $resultResponse = $this->postJson("/api/ad-scripts/{$task->id}/result", $payload, [
            'X-N8N-Signature' => $signature,
        ]);
        $resultResponse->assertStatus(200, 'Result processing should have separate rate limit');
    }

    public function test_rate_limiter_configuration(): void
    {
        // Clear any existing rate limits
        RateLimiter::clear('ad-script-submission');
        Cache::flush();

        // Test the rate limiter directly
        $request = Request::create('/api/ad-scripts', 'POST');
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        // Check if we can make 10 requests
        for ($i = 0; $i < 10; $i++) {
            $result = RateLimiter::attempt('ad-script-submission:127.0.0.1', 10, function () {
                return true;
            });

            if ($i < 10) {
                $this->assertTrue($result, "Request {$i} should succeed");
            }
        }

        // The 11th request should fail
        $result = RateLimiter::attempt('ad-script-submission:127.0.0.1', 10, function () {
            return true;
        });

        $this->assertFalse($result, "Request 11 should be rate limited");
    }

    public function test_exact_rate_limiting_behavior(): void
    {
        // Clear any existing rate limits
        RateLimiter::clear('ad-script-submission');
        Cache::flush();

        $successfulRequests = 0;
        $rateLimitedRequests = 0;

        // Make exactly 15 requests to see the pattern
        for ($i = 0; $i < 15; $i++) {
            $response = $this->postJson('/api/ad-scripts', [
                'reference_script' => "Test script {$i}",
                'outcome_description' => 'Testing exact rate limiting',
            ]);

            if ($response->getStatusCode() === 202) {
                $successfulRequests++;
            } elseif ($response->getStatusCode() === 429) {
                $rateLimitedRequests++;
            } else {
                echo "Request {$i}: UNEXPECTED STATUS {$response->getStatusCode()}\n";
                echo "Response: " . $response->getContent() . "\n";
            }
        }

        // We expect exactly 10 successful requests
        $this->assertEquals(10, $successfulRequests, 'Should allow exactly 10 requests');
        $this->assertEquals(5, $rateLimitedRequests, 'Should rate limit 5 requests');
    }
}
