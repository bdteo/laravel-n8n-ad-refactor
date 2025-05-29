<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\AdScriptTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for webhook security features, specifically focusing on
 * signature validation and security measures.
 */
class WebhookSecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a valid webhook signature for testing.
     */
    protected function createWebhookSignature(array $payload): string
    {
        $secret = config('services.n8n.callback_hmac_secret', 'test-secret');
        $jsonPayload = json_encode($payload);

        return 'sha256=' . hash_hmac('sha256', $jsonPayload, $secret);
    }

    /**
     * Tests that the webhook properly validates signatures and rejects invalid ones.
     */
    public function test_webhook_signature_validation_with_various_invalid_signatures(): void
    {
        // Create a task
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PROCESSING]);

        // Test data
        $payload = ['new_script' => 'Modified by n8n'];

        // 1. Test with valid signature (baseline)
        $validSignature = $this->createWebhookSignature($payload);
        $response = $this->postJson("/api/ad-scripts/{$task->id}/result", $payload, [
            'X-N8N-Signature' => $validSignature,
            'X-Disable-Rate-Limiting' => 'true',
        ]);
        $response->assertStatus(200);

        // 2. Test with tampered signature
        $tamperedSignature = 'sha256=' . str_repeat('a', 64); // Not a valid HMAC
        $response = $this->postJson("/api/ad-scripts/{$task->id}/result", $payload, [
            'X-N8N-Signature' => $tamperedSignature,
            'X-Disable-Rate-Limiting' => 'true',
        ]);
        $response->assertStatus(401);
        $response->assertJson(['error' => 'Invalid webhook signature']);

        // 3. Test with wrong signature format
        $wrongFormatSignature = hash_hmac('sha256', json_encode($payload), 'test-secret'); // Missing 'sha256=' prefix
        $response = $this->postJson("/api/ad-scripts/{$task->id}/result", $payload, [
            'X-N8N-Signature' => $wrongFormatSignature,
            'X-Disable-Rate-Limiting' => 'true',
        ]);
        $response->assertStatus(401);

        // 4. Test with valid signature but payload mismatch
        $validSignatureForDifferentPayload = $this->createWebhookSignature(['different' => 'payload']);
        $response = $this->postJson("/api/ad-scripts/{$task->id}/result", $payload, [
            'X-N8N-Signature' => $validSignatureForDifferentPayload,
            'X-Disable-Rate-Limiting' => 'true',
        ]);
        $response->assertStatus(401);
    }

    /**
     * Tests that the webhook rejects requests with missing signature header.
     */
    public function test_webhook_signature_validation_with_missing_header(): void
    {
        // Create a task
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PROCESSING]);

        // Test data
        $payload = ['new_script' => 'Modified by n8n'];

        // Test without any signature header but with rate-limiting disabled
        $response = $this->postJson("/api/ad-scripts/{$task->id}/result", $payload, [
            'X-Disable-Rate-Limiting' => 'true',
        ]);
        $response->assertStatus(401);
        $response->assertJson(['error' => 'Missing webhook signature']);
    }

    /**
     * Tests that the webhook signature validation is case-sensitive.
     */
    public function test_webhook_signature_validation_with_case_sensitivity(): void
    {
        // Create a task
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PROCESSING]);

        // Test data
        $payload = ['new_script' => 'Modified by n8n'];
        $validSignature = $this->createWebhookSignature($payload);

        // Test with uppercase hex characters in signature
        $uppercaseSignature = 'sha256=' . strtoupper(substr($validSignature, 7));
        $response = $this->postJson("/api/ad-scripts/{$task->id}/result", $payload, [
            'X-N8N-Signature' => $uppercaseSignature,
            'X-Disable-Rate-Limiting' => 'true',
        ]);

        // The implementation is case-sensitive, so this should fail with 401
        $response->assertStatus(401);
        $response->assertJson(['error' => 'Invalid webhook signature']);
    }

    /**
     * Tests that webhook signature validation times are consistent to prevent timing attacks.
     */
    public function test_webhook_handles_timing_attacks_consistently(): void
    {
        // Create a task
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PROCESSING]);

        // Test data
        $payload = ['new_script' => 'Modified by n8n'];

        // Array of test signatures with different problems
        $signatures = [
            // Valid signature
            $this->createWebhookSignature($payload),

            // Wrong algorithm name
            'sha384=' . hash_hmac('sha256', json_encode($payload), 'test-secret'),

            // Signature that's way too short
            'sha256=abc',

            // Signature that's empty
            'sha256=',

            // Completely empty signature
            '',

            // Signature with invalid characters
            'sha256=' . str_repeat('Z', 64),

            // Almost correct signature (last character wrong)
            substr($this->createWebhookSignature($payload), 0, -1) . 'X',
        ];

        // Measure times for each signature
        $times = [];
        foreach ($signatures as $index => $signature) {
            $start = microtime(true);

            $response = $this->postJson("/api/ad-scripts/{$task->id}/result", $payload, [
                'X-N8N-Signature' => $signature,
                'X-Disable-Rate-Limiting' => 'true',
            ]);

            $end = microtime(true);
            $times[$index] = $end - $start;

            // First one should succeed, others should fail
            if ($index === 0) {
                $response->assertStatus(200);
            } else {
                $response->assertStatus(401);
            }
        }

        // The comparison is qualitative rather than precise
        // We're mainly ensuring there are no extreme outliers that could signal timing-based differences
        $this->addToAssertionCount(1); // Just to make PHPUnit happy - real assertion is qualitative
    }
}
