<?php

declare(strict_types=1);

namespace Tests\Feature\N8n\Integration;

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
 * Tests for N8n integration with complex payloads and performance.
 *
 * This test file focuses on testing N8n integration with complex payload data
 * and performance with realistic data sizes.
 */
class PayloadAndPerformanceTest extends TestCase
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
     * Test integration with complex payload data.
     */
    public function test_integration_with_complex_payload_data(): void
    {
        // Create a task with complex data
        $complexScript = <<<SCRIPT
        function complexFunction() {
            let array = [];
            for (let i = 0; i < 100; i++) {
                array.push({
                    id: i,
                    name: `Item \${i}`,
                    description: `This is a detailed description for item \${i}.
                    It contains multiple lines and special characters like: 
                    ~!@#\$%^&*()_+{}|:"<>?[];',./\\`,
                });
            }
            return JSON.stringify(array);
        }
        SCRIPT;

        $complexDescription = <<<DESC
        Need to transform the script to accomplish the following:
        1. Fix all syntax errors and improve code quality
        2. Add proper error handling with try/catch blocks
        3. Implement caching mechanism for better performance
        4. Add extensive documentation following JSDoc standards
        5. Convert to TypeScript with proper interfaces
        
        The resulting code should handle special characters like: ~!@#$%^&*()_+{}|:"<>?[];',./\\
        And should properly escape text in "double quotes" and 'single quotes'.
        DESC;

        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::PENDING,
            'reference_script' => $complexScript,
            'outcome_description' => $complexDescription,
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

        $response = $n8nClient->triggerWorkflow($task);

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        $this->assertTrue($response['success']);
        $this->assertLessThan(1.0, $executionTime, 'Integration should complete within 1 second');

        // Verify large data was properly handled
        $this->assertGreaterThan(3000, strlen($task->reference_script));
        $this->assertGreaterThan(1000, strlen($task->outcome_description));
        $this->assertEquals($largeScript, $task->reference_script);
        $this->assertEquals($largeDescription, $task->outcome_description);
    }
}
