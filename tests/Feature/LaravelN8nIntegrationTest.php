<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\N8nClientInterface;
use App\DTOs\N8nWebhookPayload;
use App\Enums\TaskStatus;
use App\Exceptions\N8nClientException;
use App\Jobs\TriggerN8nWorkflow;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Integration tests specifically focused on Laravel-n8n communication.
 *
 * These tests complement the existing AdScriptIntegrationTest by focusing on:
 * - Service layer integration with real instances
 * - HTTP client integration with actual HTTP communication patterns
 * - Configuration-based integration scenarios
 * - Error propagation through the entire stack
 * - Queue integration with real job processing
 */
class LaravelN8nIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up test configuration
        config([
            'services.n8n.webhook_secret' => 'integration-test-secret',
            'services.n8n.webhook_url' => 'https://test-n8n.example.com/webhook/test',
            'services.n8n.timeout' => 30,
            'services.n8n.retry_attempts' => 3,
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
            webhookSecret: 'test-secret'
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

        // Test webhook payload creation
        $payload = $adScriptTaskService->createWebhookPayload($task);
        $this->assertInstanceOf(N8nWebhookPayload::class, $payload);
        $this->assertEquals($task->id, $payload->taskId);
        $this->assertEquals($task->reference_script, $payload->referenceScript);
        $this->assertEquals($task->outcome_description, $payload->outcomeDescription);

        // Test n8n client trigger
        $response = $n8nClient->triggerWorkflow($payload);
        $this->assertIsArray($response);
        $this->assertTrue($response['success']);
        $this->assertEquals('service-integration-test', $response['workflow_id']);

        // Verify the webhook URL is accessible
        $this->assertEquals('https://test-n8n.example.com/webhook/test', $n8nClient->getWebhookUrl());
    }

    /**
     * Test HTTP client integration with various response scenarios.
     */
    public function test_http_client_integration_with_response_scenarios(): void
    {
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PENDING]);

        // Create a mock HTTP handler for successful response
        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'success' => true,
                'workflow_id' => 'http-integration-test',
                'execution_id' => 'exec-456',
                'timestamp' => now()->toISOString(),
            ])),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $n8nClient = new HttpN8nClient(
            httpClient: $httpClient,
            webhookUrl: 'https://test-n8n.example.com/webhook/test',
            webhookSecret: 'test-secret'
        );

        $adScriptTaskService = app(AdScriptTaskService::class);

        $payload = $adScriptTaskService->createWebhookPayload($task);
        $response = $n8nClient->triggerWorkflow($payload);

        $this->assertIsArray($response);
        $this->assertTrue($response['success']);
        $this->assertEquals('http-integration-test', $response['workflow_id']);
    }

    /**
     * Test HTTP client integration with timeout scenarios.
     */
    public function test_http_client_integration_with_timeout_scenarios(): void
    {
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PENDING]);

        // Create a mock HTTP handler for timeout response - need enough responses for retries
        $mockHandler = new MockHandler([
            new Response(408, [], ''), // Request Timeout - attempt 1
            new Response(408, [], ''), // Request Timeout - attempt 2
            new Response(408, [], ''), // Request Timeout - attempt 3
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $n8nClient = new HttpN8nClient(
            httpClient: $httpClient,
            webhookUrl: 'https://test-n8n.example.com/webhook/test',
            webhookSecret: 'test-secret'
        );

        $adScriptTaskService = app(AdScriptTaskService::class);
        $payload = $adScriptTaskService->createWebhookPayload($task);

        $this->expectException(N8nClientException::class);
        $this->expectExceptionMessage('N8n webhook returned HTTP 408');

        $n8nClient->triggerWorkflow($payload);
    }

    /**
     * Test HTTP client integration with server error scenarios.
     */
    public function test_http_client_integration_with_server_errors(): void
    {
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PENDING]);

        // Create a mock HTTP handler for server error response - need enough responses for retries
        $mockHandler = new MockHandler([
            new Response(500, [], json_encode([
                'error' => 'Internal server error',
                'message' => 'Workflow execution failed',
            ])), // attempt 1
            new Response(500, [], json_encode([
                'error' => 'Internal server error',
                'message' => 'Workflow execution failed',
            ])), // attempt 2
            new Response(500, [], json_encode([
                'error' => 'Internal server error',
                'message' => 'Workflow execution failed',
            ])), // attempt 3
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $n8nClient = new HttpN8nClient(
            httpClient: $httpClient,
            webhookUrl: 'https://test-n8n.example.com/webhook/test',
            webhookSecret: 'test-secret'
        );

        $adScriptTaskService = app(AdScriptTaskService::class);
        $payload = $adScriptTaskService->createWebhookPayload($task);

        $this->expectException(N8nClientException::class);
        $this->expectExceptionMessage('N8n webhook returned HTTP 500');

        $n8nClient->triggerWorkflow($payload);
    }

    /**
     * Test queue integration with real job processing using mocked N8n client.
     */
    public function test_queue_integration_with_real_job_processing(): void
    {
        // Don't fake the queue - we want to test real job processing
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PENDING]);

        // Mock the N8n client interface
        $mockN8nClient = Mockery::mock(N8nClientInterface::class);
        $this->app->instance(N8nClientInterface::class, $mockN8nClient);

        $mockN8nClient->shouldReceive('getWebhookUrl')
            ->andReturn('https://test-n8n.example.com/webhook/test');

        $mockN8nClient->shouldReceive('triggerWorkflow')
            ->once()
            ->andReturn([
                'success' => true,
                'workflow_id' => 'queue-integration-test',
                'execution_id' => 'exec-789',
            ]);

        // Dispatch the job and process it immediately
        $job = new TriggerN8nWorkflow($task);

        // Process the job synchronously
        $job->handle(app(AdScriptTaskService::class), $mockN8nClient);

        // Verify task status was updated
        $task->refresh();
        $this->assertEquals(TaskStatus::PROCESSING, $task->status);

        // Verify logging occurred
        Log::shouldHaveReceived('info')
            ->with('Starting n8n workflow trigger', \Mockery::type('array'))
            ->once();

        Log::shouldHaveReceived('info')
            ->with('Successfully triggered n8n workflow', \Mockery::type('array'))
            ->once();
    }

    /**
     * Test queue integration with job retry mechanisms.
     */
    public function test_queue_integration_with_job_retry_mechanisms(): void
    {
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PENDING]);

        // Mock the N8n client interface with failures then success
        $mockN8nClient = Mockery::mock(N8nClientInterface::class);
        $this->app->instance(N8nClientInterface::class, $mockN8nClient);

        $mockN8nClient->shouldReceive('getWebhookUrl')
            ->andReturn('https://test-n8n.example.com/webhook/test');

        $job = new TriggerN8nWorkflow($task);

        // First attempt should fail
        $mockN8nClient->shouldReceive('triggerWorkflow')
            ->once()
            ->andThrow(N8nClientException::httpError(503, 'Service Unavailable'));

        try {
            $job->handle(app(AdScriptTaskService::class), $mockN8nClient);
            $this->fail('First attempt should have failed');
        } catch (N8nClientException $e) {
            $this->assertStringContainsString('N8n webhook returned HTTP 503', $e->getMessage());
        }

        // Task should be processing after first attempt (markAsProcessing was called)
        $task->refresh();
        $this->assertEquals(TaskStatus::PROCESSING, $task->status);

        // Reset task to pending for second attempt
        $task->update(['status' => TaskStatus::PENDING]);

        // Second attempt should also fail
        $mockN8nClient->shouldReceive('triggerWorkflow')
            ->once()
            ->andThrow(N8nClientException::httpError(503, 'Service Unavailable'));

        try {
            $job->handle(app(AdScriptTaskService::class), $mockN8nClient);
            $this->fail('Second attempt should have failed');
        } catch (N8nClientException $e) {
            $this->assertStringContainsString('N8n webhook returned HTTP 503', $e->getMessage());
        }

        // Reset task to pending for third attempt
        $task->refresh();
        $task->update(['status' => TaskStatus::PENDING]);

        // Third attempt should succeed
        $mockN8nClient->shouldReceive('triggerWorkflow')
            ->once()
            ->andReturn(['success' => true, 'workflow_id' => 'retry-test']);

        $job->handle(app(AdScriptTaskService::class), $mockN8nClient);

        $task->refresh();
        $this->assertEquals(TaskStatus::PROCESSING, $task->status);
    }

    /**
     * Test configuration integration with different environment settings.
     */
    public function test_configuration_integration_with_environment_settings(): void
    {
        // Test with custom configuration
        config([
            'services.n8n.webhook_url' => 'https://custom-n8n.example.com/webhook/custom',
            'services.n8n.webhook_secret' => 'custom-secret-key',
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

        // Create client with custom configuration
        $n8nClient = new HttpN8nClient(httpClient: $httpClient);
        $this->assertEquals('https://custom-n8n.example.com/webhook/custom', $n8nClient->getWebhookUrl());

        $adScriptTaskService = app(AdScriptTaskService::class);
        $payload = $adScriptTaskService->createWebhookPayload($task);

        $response = $n8nClient->triggerWorkflow($payload);
        $this->assertTrue($response['success']);
    }

    /**
     * Test configuration integration with invalid settings.
     */
    public function test_configuration_integration_with_invalid_settings(): void
    {
        // Test with invalid webhook URL
        $this->expectException(N8nClientException::class);
        $this->expectExceptionMessage('Webhook URL is not valid');

        new HttpN8nClient(
            webhookUrl: 'invalid-url',
            webhookSecret: 'test-secret'
        );
    }

    /**
     * Test configuration integration with missing required settings.
     */
    public function test_configuration_integration_with_missing_settings(): void
    {
        // Test with empty webhook URL
        $this->expectException(N8nClientException::class);
        $this->expectExceptionMessage('Webhook URL is required');

        new HttpN8nClient(
            webhookUrl: '',
            webhookSecret: 'test-secret'
        );
    }

    /**
     * Test error propagation through the entire stack.
     */
    public function test_error_propagation_through_entire_stack(): void
    {
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PENDING]);

        // Mock the N8n client interface with connection failure
        $mockN8nClient = Mockery::mock(N8nClientInterface::class);
        $this->app->instance(N8nClientInterface::class, $mockN8nClient);

        $mockN8nClient->shouldReceive('getWebhookUrl')
            ->andReturn('https://test-n8n.example.com/webhook/test');

        $mockN8nClient->shouldReceive('triggerWorkflow')
            ->once()
            ->andThrow(N8nClientException::connectionFailed('https://test-n8n.example.com/webhook/test', 'Connection refused'));

        $job = new TriggerN8nWorkflow($task);

        // Test error propagation from HTTP client through job to service
        try {
            $job->handle(app(AdScriptTaskService::class), $mockN8nClient);
            $this->fail('Job should have thrown an exception');
        } catch (N8nClientException $e) {
            $this->assertStringContainsString('Failed to connect to n8n webhook', $e->getMessage());
            $this->assertStringContainsString('Connection refused', $e->getMessage());
        }

        // Verify task status - it may be processing if markAsProcessing was called before the exception
        $task->refresh();
        $this->assertTrue(
            $task->status === TaskStatus::PENDING || $task->status === TaskStatus::PROCESSING,
            'Task status should be either pending or processing after connection failure'
        );

        // Verify error logging occurred
        Log::shouldHaveReceived('error')
            ->with('N8n client error while triggering workflow', \Mockery::type('array'))
            ->once();
    }

    /**
     * Test error propagation with job failure handling.
     */
    public function test_error_propagation_with_job_failure_handling(): void
    {
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PENDING]);

        // Mock the N8n client interface with persistent failure
        $mockN8nClient = Mockery::mock(N8nClientInterface::class);
        $this->app->instance(N8nClientInterface::class, $mockN8nClient);

        $mockN8nClient->shouldReceive('getWebhookUrl')
            ->andReturn('https://test-n8n.example.com/webhook/test');

        $mockN8nClient->shouldReceive('triggerWorkflow')
            ->once()
            ->andThrow(N8nClientException::httpError(503, 'Service Unavailable'));

        $job = new TriggerN8nWorkflow($task);

        // Create a custom job instance that simulates being on the final attempt
        $jobWithMaxAttempts = new class ($task) extends TriggerN8nWorkflow {
            public function attempts(): int
            {
                return 3; // Simulate max attempts reached
            }
        };

        try {
            $jobWithMaxAttempts->handle(app(AdScriptTaskService::class), $mockN8nClient);
            $this->fail('Job should have thrown an exception');
        } catch (N8nClientException $e) {
            $this->assertStringContainsString('N8n webhook returned HTTP 503', $e->getMessage());
        }

        // Verify task was marked as failed after max attempts
        $task->refresh();
        $this->assertEquals(TaskStatus::FAILED, $task->status);
        $this->assertStringContainsString('Failed to trigger n8n workflow after 3 attempts', $task->error_details);
    }

    /**
     * Test HTTP client availability checking.
     */
    public function test_http_client_availability_checking(): void
    {
        // Test service available (200 response)
        $mockHandler = new MockHandler([
            new Response(200, [], json_encode(['status' => 'ok'])),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $n8nClient = new HttpN8nClient(
            httpClient: $httpClient,
            webhookUrl: 'https://test-n8n.example.com/webhook/test',
            webhookSecret: 'test-secret'
        );

        $this->assertTrue($n8nClient->isAvailable());

        // Test service unavailable (500 response)
        $mockHandler = new MockHandler([
            new Response(500, [], json_encode(['error' => 'server error'])),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $n8nClient = new HttpN8nClient(
            httpClient: $httpClient,
            webhookUrl: 'https://test-n8n.example.com/webhook/test',
            webhookSecret: 'test-secret'
        );

        $this->assertFalse($n8nClient->isAvailable());

        // Test connection failure
        $mockHandler = new MockHandler([
            new ConnectException('Connection refused', new Request('GET', 'test')),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $n8nClient = new HttpN8nClient(
            httpClient: $httpClient,
            webhookUrl: 'https://test-n8n.example.com/webhook/test',
            webhookSecret: 'test-secret'
        );

        $this->assertFalse($n8nClient->isAvailable());
    }

    /**
     * Test integration with complex payload data.
     */
    public function test_integration_with_complex_payload_data(): void
    {
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::PENDING,
            'reference_script' => 'Complex script with unicode: 🚀 café naïve résumé',
            'outcome_description' => 'Test with special chars: @#$%^&*()_+-=[]{}|;:,.<>?',
        ]);

        // Create a mock HTTP handler for complex payload test
        $mockHandler = new MockHandler([
            new Response(200, [], json_encode([
                'success' => true,
                'workflow_id' => 'complex-payload-test',
            ])),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $n8nClient = new HttpN8nClient(
            httpClient: $httpClient,
            webhookUrl: 'https://test-n8n.example.com/webhook/test',
            webhookSecret: 'test-secret'
        );

        $adScriptTaskService = app(AdScriptTaskService::class);

        $payload = $adScriptTaskService->createWebhookPayload($task);
        $response = $n8nClient->triggerWorkflow($payload);

        $this->assertTrue($response['success']);

        // Verify payload contains complex data
        $this->assertEquals($task->reference_script, $payload->referenceScript);
        $this->assertEquals($task->outcome_description, $payload->outcomeDescription);
        $this->assertStringContainsString('🚀', $payload->referenceScript);
        $this->assertStringContainsString('@#$%', $payload->outcomeDescription);
    }

    /**
     * Test integration with webhook signature verification.
     */
    public function test_integration_with_webhook_signature_verification(): void
    {
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PROCESSING]);

        // Test valid signature
        $resultPayload = [
            'new_script' => 'Integration test result script',
            'analysis' => ['test' => 'signature verification'],
        ];

        $secret = config('services.n8n.webhook_secret');
        $signature = 'sha256=' . hash_hmac('sha256', json_encode($resultPayload), $secret);

        $response = $this->postJson("/api/ad-scripts/{$task->id}/result", $resultPayload, [
            'X-N8N-Signature' => $signature,
        ]);

        $response->assertStatus(200);

        // Test invalid signature
        $invalidSignature = 'sha256=' . hash_hmac('sha256', json_encode($resultPayload), 'wrong-secret');

        $response = $this->postJson("/api/ad-scripts/{$task->id}/result", $resultPayload, [
            'X-N8N-Signature' => $invalidSignature,
        ]);

        $response->assertStatus(401);
    }

    /**
     * Test integration performance with realistic data sizes.
     */
    public function test_integration_performance_with_realistic_data_sizes(): void
    {
        // Create task with realistic data size
        $largeScript = str_repeat('This is a realistic ad script content. ', 100); // ~3.7KB
        $largeDescription = str_repeat('Detailed outcome description. ', 50); // ~1.5KB

        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::PENDING,
            'reference_script' => $largeScript,
            'outcome_description' => $largeDescription,
        ]);

        // Create a mock HTTP handler for performance test
        $mockHandler = new MockHandler([
            new Response(200, [], json_encode([
                'success' => true,
                'workflow_id' => 'performance-test',
            ])),
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $n8nClient = new HttpN8nClient(
            httpClient: $httpClient,
            webhookUrl: 'https://test-n8n.example.com/webhook/test',
            webhookSecret: 'test-secret'
        );

        $startTime = microtime(true);

        $adScriptTaskService = app(AdScriptTaskService::class);

        $payload = $adScriptTaskService->createWebhookPayload($task);
        $response = $n8nClient->triggerWorkflow($payload);

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        $this->assertTrue($response['success']);
        $this->assertLessThan(1.0, $executionTime, 'Integration should complete within 1 second');

        // Verify large payload was properly handled
        $this->assertGreaterThan(3000, strlen($payload->referenceScript));
        $this->assertGreaterThan(1000, strlen($payload->outcomeDescription));
        $this->assertEquals($largeScript, $payload->referenceScript);
        $this->assertEquals($largeDescription, $payload->outcomeDescription);
    }

    /**
     * Test HTTP client retry mechanism with Guzzle mock handler.
     */
    public function test_http_client_retry_mechanism_with_guzzle_mock(): void
    {
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PENDING]);

        // Create a mock HTTP handler that fails twice then succeeds
        $mockHandler = new MockHandler([
            new Response(503, [], ''), // First failure
            new Response(503, [], ''), // Second failure
            new Response(200, [], json_encode(['success' => true, 'workflow_id' => 'retry-success'])), // Success
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $n8nClient = new HttpN8nClient(
            httpClient: $httpClient,
            webhookUrl: 'https://test-n8n.example.com/webhook/test',
            webhookSecret: 'test-secret',
            retryAttempts: 3
        );

        $adScriptTaskService = app(AdScriptTaskService::class);
        $payload = $adScriptTaskService->createWebhookPayload($task);

        // Should succeed after retries
        $response = $n8nClient->triggerWorkflow($payload);
        $this->assertTrue($response['success']);
        $this->assertEquals('retry-success', $response['workflow_id']);
    }

    /**
     * Test HTTP client with connection timeout.
     */
    public function test_http_client_with_connection_timeout(): void
    {
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PENDING]);

        // Create a mock HTTP handler that throws a connection exception - need enough for retries
        $mockHandler = new MockHandler([
            new ConnectException('Connection timeout', new Request('POST', 'test')), // attempt 1
            new ConnectException('Connection timeout', new Request('POST', 'test')), // attempt 2
            new ConnectException('Connection timeout', new Request('POST', 'test')), // attempt 3
        ]);

        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        $n8nClient = new HttpN8nClient(
            httpClient: $httpClient,
            webhookUrl: 'https://test-n8n.example.com/webhook/test',
            webhookSecret: 'test-secret'
        );

        $adScriptTaskService = app(AdScriptTaskService::class);
        $payload = $adScriptTaskService->createWebhookPayload($task);

        $this->expectException(N8nClientException::class);
        $this->expectExceptionMessage('Failed to connect to n8n webhook');

        $n8nClient->triggerWorkflow($payload);
    }
}
