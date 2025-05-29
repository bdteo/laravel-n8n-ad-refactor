<?php

declare(strict_types=1);

namespace Tests\Feature\N8n;

use App\DTOs\N8nWebhookPayload;
use App\Enums\TaskStatus;
use App\Exceptions\N8nConfigurationException;
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
 * These tests focus on the service layer components that integrate with n8n,
 * including configuration management and payload handling.
 */
class N8nServiceIntegrationTest extends TestCase
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
     * Test configuration integration with different environment settings.
     */
    public function test_configuration_integration_with_environment_settings(): void
    {
        $originalConfig = config('services.n8n');

        // Test with different configuration values
        $testConfigs = [
            // Test with minimum required values
            [
                'webhook_url' => 'https://test-min.example.com/webhook',
                'auth_header_key' => 'X-Min-Auth',
                'auth_header_value' => 'min-secret',
            ],
            // Test with all values
            [
                'webhook_url' => 'https://test-full.example.com/webhook',
                'auth_header_key' => 'X-Full-Auth',
                'auth_header_value' => 'full-secret',
                'timeout' => 60,
                'retry_attempts' => 5,
                'callback_hmac_secret' => 'full-hmac-secret',
            ],
            // Test with custom retry values
            [
                'webhook_url' => 'https://test-retry.example.com/webhook',
                'auth_header_key' => 'X-Retry-Auth',
                'auth_header_value' => 'retry-secret',
                'timeout' => 10,
                'retry_attempts' => 1,
            ],
        ];

        foreach ($testConfigs as $index => $testConfig) {
            config(['services.n8n' => array_merge($originalConfig, $testConfig)]);

            // Create a client with the current config but pass the webhook URL directly to make sure it's used
            $n8nClient = new HttpN8nClient(
                webhookUrl: $testConfig['webhook_url'],
                authHeaderKey: $testConfig['auth_header_key'],
                authHeaderValue: $testConfig['auth_header_value'],
                timeout: $testConfig['timeout'] ?? 30,
                retryAttempts: $testConfig['retry_attempts'] ?? 3
            );

            // Create a reflection to check private properties
            $reflection = new \ReflectionClass($n8nClient);

            $webhookUrlProp = $reflection->getProperty('webhookUrl');
            $webhookUrlProp->setAccessible(true);
            $this->assertEquals($testConfig['webhook_url'], $webhookUrlProp->getValue($n8nClient));

            $authHeaderKeyProp = $reflection->getProperty('authHeaderKey');
            $authHeaderKeyProp->setAccessible(true);
            $this->assertEquals($testConfig['auth_header_key'], $authHeaderKeyProp->getValue($n8nClient));

            $authHeaderValueProp = $reflection->getProperty('authHeaderValue');
            $authHeaderValueProp->setAccessible(true);
            $this->assertEquals($testConfig['auth_header_value'], $authHeaderValueProp->getValue($n8nClient));

            if (isset($testConfig['timeout'])) {
                $timeoutProp = $reflection->getProperty('timeout');
                $timeoutProp->setAccessible(true);
                $this->assertEquals($testConfig['timeout'], $timeoutProp->getValue($n8nClient));
            }

            if (isset($testConfig['retry_attempts'])) {
                $retryAttemptsProp = $reflection->getProperty('retryAttempts');
                $retryAttemptsProp->setAccessible(true);
                $this->assertEquals($testConfig['retry_attempts'], $retryAttemptsProp->getValue($n8nClient));
            }
        }

        // Restore original config
        config(['services.n8n' => $originalConfig]);
    }

    /**
     * Test configuration integration with invalid settings.
     */
    public function test_configuration_integration_with_invalid_settings(): void
    {
        $originalConfig = config('services.n8n');

        // Test with invalid timeout
        config(['services.n8n.timeout' => 'not-a-number']);
        $n8nClient = app(HttpN8nClient::class);

        $reflection = new \ReflectionClass($n8nClient);
        $timeoutProp = $reflection->getProperty('timeout');
        $timeoutProp->setAccessible(true);

        // Should fall back to default
        $this->assertEquals(30, $timeoutProp->getValue($n8nClient));

        // Restore original config
        config(['services.n8n' => $originalConfig]);
    }

    /**
     * Test configuration integration with missing webhook URL.
     */
    public function test_configuration_integration_with_missing_webhook_url(): void
    {
        // Remove webhook URL from config
        config(['services.n8n.trigger_webhook_url' => null]);

        try {
            // This should throw
            $n8nClient = new HttpN8nClient(
                httpClient: new Client(),
                // No webhook URL
                webhookUrl: null,
                authHeaderKey: 'X-Test-Auth',
                authHeaderValue: 'test-secret'
            );

            // Should not reach here
            $this->fail('Expected N8nConfigurationException was not thrown');
        } catch (N8nConfigurationException $e) {
            $this->assertStringContainsString('webhook url', strtolower($e->getMessage()));
        }
    }

    /**
     * Test configuration integration with missing auth header key.
     */
    public function test_configuration_integration_with_missing_auth_header_key(): void
    {
        // Remove auth header key but provide URL
        config(['services.n8n.auth_header_key' => null]);

        try {
            // This should throw
            $n8nClient = new HttpN8nClient(
                httpClient: new Client(),
                webhookUrl: 'https://test-n8n.example.com/webhook/test',
                // No auth header key
                authHeaderKey: null,
                authHeaderValue: 'test-secret'
            );

            // Should not reach here
            $this->fail('Expected N8nConfigurationException was not thrown');
        } catch (N8nConfigurationException $e) {
            $this->assertStringContainsString('auth header key', strtolower($e->getMessage()));
        }
    }

    /**
     * Test configuration integration with missing auth header value.
     */
    public function test_configuration_integration_with_missing_auth_header_value(): void
    {
        // Remove auth header value but provide URL and key
        config(['services.n8n.auth_header_value' => null]);

        try {
            // This should throw
            $n8nClient = new HttpN8nClient(
                httpClient: new Client(),
                webhookUrl: 'https://test-n8n.example.com/webhook/test',
                authHeaderKey: 'X-Test-Auth',
                // No auth header value
                authHeaderValue: null
            );

            // Should not reach here
            $this->fail('Expected N8nConfigurationException was not thrown');
        } catch (N8nConfigurationException $e) {
            $this->assertStringContainsString('auth header value', strtolower($e->getMessage()));
        }
    }

    /**
     * Test integration with complex payload data.
     */
    public function test_integration_with_complex_payload_data(): void
    {
        // Create a task with complex data
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::PENDING,
            'reference_script' => "Complex script with special characters: \n\t\"'<>&",
            'outcome_description' => 'Test complex data with JSON: {"key":"value"}',
        ]);

        // Create a mock HTTP handler
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
            authHeaderKey: 'X-Test-Auth',
            authHeaderValue: 'test-secret'
        );

        // Test that the client can handle the complex task data directly
        $response = $n8nClient->triggerWorkflow($task);
        $this->assertTrue($response['success']);
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

    /**
     * Test integration performance with realistic data sizes.
     */
    public function test_integration_performance_with_realistic_data_sizes(): void
    {
        // Create a large script and description
        $largeScript = str_repeat('This is a very long script with repeated content. ', 100);
        $largeDescription = str_repeat('This is a lengthy description of the desired outcome. ', 30);

        // Create a task with large data
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::PENDING,
            'reference_script' => $largeScript,
            'outcome_description' => $largeDescription,
        ]);

        // Create a mock HTTP handler
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
            authHeaderKey: 'X-Test-Auth',
            authHeaderValue: 'test-secret'
        );

        $startTime = microtime(true);

        $adScriptTaskService = app(AdScriptTaskService::class);

        // Create the webhook payload for verification
        $payload = $adScriptTaskService->createWebhookPayload($task);

        $response = $n8nClient->triggerWorkflow($task);

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
}
