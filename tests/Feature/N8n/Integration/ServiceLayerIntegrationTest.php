<?php

declare(strict_types=1);

namespace Tests\Feature\N8n\Integration;

use App\DTOs\N8nWebhookPayload;
use App\Enums\TaskStatus;
use App\Models\AdScriptTask;
use App\Services\AdScriptTaskService;
use App\Services\HttpN8nClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Tests for N8n service layer integration.
 *
 * This test file focuses on the basic service layer integration with N8n.
 */
class ServiceLayerIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up test configuration
        config([
            // Old config for backward compatibility in some tests
            'services.n8n.webhook_secret' => 'integration-test-secret',

            // New configs that match our updated implementation
            'services.n8n.webhook_url' => 'https://test-n8n.example.com/webhook/test',
            'services.n8n.auth_header_key' => 'X-Test-Auth',
            'services.n8n.auth_header_value' => 'test-auth-value',
            'services.n8n.callback_hmac_secret' => 'integration-test-secret',
            'services.n8n.timeout' => 30,
            'services.n8n.retry_attempts' => 3,

            // Enable integration test mode for this test class
            'services.n8n.integration_test_mode' => true,
        ]);

        // Spy on logs
        Log::spy();
    }

    /**
     * Test service layer integration with real service instances.
     */
    public function test_service_layer_integration_with_real_instances(): void
    {
        // Create a mock HTTP handler for the HttpN8nClient
        $mockHandler = new MockHandler([
            new Response(200, [], json_encode([
                'success' => true,
                'workflow_id' => 'service-integration-test',
                'execution_id' => 'exec-123',
            ])),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        // Create real service instances
        $n8nClient = new HttpN8nClient(
            httpClient: $httpClient,
            webhookUrl: 'https://test-n8n.example.com/webhook/test',
            authHeaderKey: 'X-Test-Auth',
            authHeaderValue: 'test-secret'
        );

        $adScriptTaskService = app(AdScriptTaskService::class);

        // Create a task
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::PENDING,
            'reference_script' => 'Service integration test script',
            'outcome_description' => 'Test service layer integration',
        ]);

        // Test the service interaction
        $this->assertTrue($adScriptTaskService->canProcess($task));
        $this->assertTrue($adScriptTaskService->markAsProcessing($task));

        $task->refresh();
        $this->assertEquals(TaskStatus::PROCESSING, $task->status);

        // Test n8n client trigger with the task directly (no need to create N8nWebhookPayload object)
        $response = $n8nClient->triggerWorkflow($task);
        $this->assertTrue($response['success']);
        $this->assertEquals('service-integration-test', $response['workflow_id']);
        $this->assertEquals('exec-123', $response['execution_id']);
    }

    /**
     * Test integration with webhook signature verification.
     */
    public function test_integration_with_webhook_signature_verification(): void
    {
        // Create a task
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PROCESSING]);

        // Prepare result payload
        $resultPayload = ['new_script' => 'Modified by n8n webhook'];

        // Create valid HMAC signature
        $secret = config('services.n8n.callback_hmac_secret');
        $signature = 'sha256=' . hash_hmac('sha256', json_encode($resultPayload), $secret);

        // Make a request with the valid signature
        $response = $this->postJson("/api/ad-scripts/{$task->id}/result", $resultPayload, [
            'X-N8N-Signature' => $signature,
            'X-Disable-Rate-Limiting' => 'true',
        ]);

        $response->assertStatus(200);

        // Test invalid signature
        $invalidSignature = 'sha256=' . hash_hmac('sha256', json_encode($resultPayload), 'wrong-secret');
        $response = $this->postJson("/api/ad-scripts/{$task->id}/result", $resultPayload, [
            'X-N8N-Signature' => $invalidSignature,
            'X-Disable-Rate-Limiting' => 'true',
        ]);

        $response->assertStatus(401);
    }
}
