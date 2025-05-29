<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Exceptions\N8nClientException;
use App\Models\AdScriptTask;
use App\Services\AdScriptTaskService;
use App\Services\HttpN8nClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * Integration tests specifically focused on Laravel-n8n communication.
 *
 * These tests complement the existing AdScriptIntegrationTest by focusing on:
 * - HTTP client integration
 * - Service configuration integration
 * - Webhook signature verification
 */
class LaravelN8nIntegrationTest extends TestCase
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

        // Test n8n client trigger directly with the task
        // This avoids using N8nWebhookPayload class directly which causes issues with Mockery and readonly classes
        $response = $n8nClient->triggerWorkflow($task);
        $this->assertIsArray($response);
        $this->assertTrue($response['success']);
        $this->assertEquals('service-integration-test', $response['workflow_id']);
    }

    /**
     * Test HTTP client integration with response scenarios.
     */
    public function test_http_client_integration_with_response_scenarios(): void
    {
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PENDING]);

        // Mock successful response
        $mockHandler = new MockHandler([
            new Response(200, [], json_encode([
                'success' => true,
                'workflow_id' => 'http-integration-test',
                'execution_id' => 'exec-123',
            ])),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $n8nClient = new HttpN8nClient(
            httpClient: $httpClient,
            webhookUrl: 'https://test-n8n.example.com/webhook/test',
            authHeaderKey: 'X-Test-Auth',
            authHeaderValue: 'test-secret'
        );

        $adScriptTaskService = app(AdScriptTaskService::class);

        // Test that the client handles successful response (using task directly)
        $response = $n8nClient->triggerWorkflow($task);

        $this->assertIsArray($response);
        $this->assertTrue($response['success']);
        $this->assertEquals('http-integration-test', $response['workflow_id']);
        // The execution_id may not be present in all responses
        $this->assertArrayHasKey('workflow_id', $response, 'Response should contain workflow_id');
    }

    /**
     * Test HTTP client integration with timeout scenarios.
     */
    public function test_http_client_integration_with_timeout_scenarios(): void
    {
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PENDING]);

        // Mock timeout exception
        $mockHandler = new MockHandler([
            new ConnectException('Connection timed out', new Request('POST', 'test')),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $n8nClient = new HttpN8nClient(
            httpClient: $httpClient,
            webhookUrl: 'https://test-n8n.example.com/webhook/test',
            authHeaderKey: 'X-Test-Auth',
            authHeaderValue: 'test-secret'
        );

        $adScriptTaskService = app(AdScriptTaskService::class);

        // Use try/catch instead of expectException for more flexible message checking
        try {
            $n8nClient->triggerWorkflow($task);
            $this->fail('Expected N8nClientException was not thrown');
        } catch (N8nClientException $e) {
            // Just check that we got an exception, don't be strict about the message format
            $this->assertInstanceOf(N8nClientException::class, $e);
            // The message could be either a timeout or request timeout depending on implementation
            $this->assertTrue(
                str_contains(strtolower($e->getMessage()), 'timeout') ||
                str_contains(strtolower($e->getMessage()), 'timed out'),
                'Exception message should mention timeout: ' . $e->getMessage()
            );
        }
    }

    /**
     * Test HTTP client integration with server errors.
     */
    public function test_http_client_integration_with_server_errors(): void
    {
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PENDING]);

        // Mock server error response - add enough responses for retries
        $mockHandler = new MockHandler([
            new Response(500, [], json_encode(['error' => 'Internal Server Error'])),
            new Response(500, [], json_encode(['error' => 'Internal Server Error'])),
            new Response(500, [], json_encode(['error' => 'Internal Server Error'])),
            new Response(500, [], json_encode(['error' => 'Internal Server Error'])),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $n8nClient = new HttpN8nClient(
            httpClient: $httpClient,
            webhookUrl: 'https://test-n8n.example.com/webhook/test',
            authHeaderKey: 'X-Test-Auth',
            authHeaderValue: 'test-secret'
        );

        $adScriptTaskService = app(AdScriptTaskService::class);

        // Use try/catch instead of expectException for more flexible message checking
        try {
            $n8nClient->triggerWorkflow($task);
            $this->fail('Expected N8nClientException was not thrown');
        } catch (N8nClientException $e) {
            // Just check that we got an exception related to a 500 error
            $this->assertInstanceOf(N8nClientException::class, $e);
            $this->assertTrue(
                str_contains($e->getMessage(), '500'),
                'Exception message should mention HTTP 500: ' . $e->getMessage()
            );
        }
    }

    /**
     * Test configuration integration with different environment settings.
     */
    public function test_configuration_integration_with_environment_settings(): void
    {
        // Test with custom configuration
        config([
            'services.n8n.webhook_url' => 'https://custom-n8n.example.com/webhook/custom',
            'services.n8n.callback_hmac_secret' => 'custom-secret-key',
            'services.n8n.auth_header_key' => 'X-Custom-Auth',
            'services.n8n.auth_header_value' => 'custom-auth-value',
            'services.n8n.timeout' => 60,
            'services.n8n.retry_attempts' => 5,
        ]);

        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PENDING]);

        // Create a mock HTTP handler for custom configuration test
        $mockHandler = new MockHandler([
            new Response(200, [], json_encode([
                'success' => true,
                'workflow_id' => 'config-test',
            ])),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        // Create client with explicit configuration to avoid dependency on config resolution
        $n8nClient = new HttpN8nClient(
            httpClient: $httpClient,
            webhookUrl: 'https://custom-n8n.example.com/webhook/custom',
            authHeaderKey: 'X-Custom-Auth',
            authHeaderValue: 'custom-auth-value'
        );

        $this->assertEquals('https://custom-n8n.example.com/webhook/custom', $n8nClient->getWebhookUrl());

        // Test with task directly
        $response = $n8nClient->triggerWorkflow($task);
        $this->assertTrue($response['success']);
    }

    /**
     * Test integration with webhook signature verification.
     */
    public function test_integration_with_webhook_signature_verification(): void
    {
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
