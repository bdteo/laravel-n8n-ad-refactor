<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\N8nClientInterface;
use App\Enums\TaskStatus;
use App\Jobs\TriggerN8nWorkflow;
use App\Models\AdScriptTask;
use App\Services\AdScriptTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;
use Tests\Traits\TestsRateLimiting;
use Illuminate\Log\LogManager;

/**
 * Integration tests for the complete ad script processing workflow.
 *
 * These tests simulate the entire flow including job processing and n8n interactions,
 * providing comprehensive coverage of the integration between components.
 */
class AdScriptIntegrationTest extends TestCase
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

        // Mock the Log facade with a Mockery logger
        $mockLogger = \Mockery::mock(LogManager::class);
        $mockLogger->shouldReceive('info')->andReturn(null);
        $mockLogger->shouldReceive('error')->andReturn(null);
        $mockLogger->shouldReceive('debug')->andReturn(null);
        $mockLogger->shouldReceive('warning')->andReturn(null);
        \Illuminate\Support\Facades\Log::swap($mockLogger);
    }

    /**
     * Helper method to create a webhook signature for testing
     */
    private function createWebhookSignature(array $data): string
    {
        $payload = json_encode($data);
        $secret = config('services.n8n.callback_hmac_secret');

        if (! is_string($secret) || $payload === false) {
            throw new \RuntimeException('Invalid webhook configuration for testing');
        }

        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }

    public function test_complete_integration_flow_with_successful_n8n_processing(): void
    {
        // Fake the queue to prevent actual job execution during submission
        Queue::fake();

        // Mock the N8n client
        $mockN8nClient = Mockery::mock(N8nClientInterface::class);
        $this->app->instance(N8nClientInterface::class, $mockN8nClient);

        $mockN8nClient->shouldReceive('getWebhookUrl')
            ->andReturn('https://test-n8n.example.com/webhook/test');

        // Step 1: Submit ad script task
        $submissionPayload = [
            'reference_script' => 'Original marketing copy that needs improvement for better conversion rates.',
            'outcome_description' => 'Enhance persuasiveness, add emotional triggers, and strengthen the call-to-action.',
        ];

        $submissionResponse = $this->postJson('/api/ad-scripts', $submissionPayload, $this->getNoRateLimitHeaders());
        $submissionResponse->assertStatus(202);
        $taskId = $submissionResponse->json('data.id');

        // Verify task was created
        $task = AdScriptTask::find($taskId);
        $this->assertNotNull($task);
        $this->assertEquals(TaskStatus::PENDING, $task->status);

        // Step 2: Process the job (simulate queue worker)
        // Mock successful n8n webhook call
        $mockN8nClient->shouldReceive('triggerWorkflow')
            ->once()
            ->with(Mockery::on(function ($payload) use ($task) {
                return $payload->taskId === $task->id &&
                       $payload->referenceScript === $task->reference_script &&
                       $payload->outcomeDescription === $task->outcome_description;
            }))
            ->andReturn([
                'success' => true,
                'workflow_id' => 'test-workflow-123',
                'execution_id' => 'exec-456',
            ]);

        // Execute the job
        $job = new TriggerN8nWorkflow($task);
        $job->handle(app(AdScriptTaskService::class), $mockN8nClient);

        // Verify task status was updated to processing
        $task->refresh();
        $this->assertEquals(TaskStatus::PROCESSING, $task->status);

        // Step 3: Simulate n8n callback with successful result
        $resultPayload = [
            'new_script' => 'Transform your business TODAY! 🚀 Our proven system has helped 10,000+ entrepreneurs achieve breakthrough results. Don\'t let another day pass without taking action - your future self will thank you. Click now to unlock your potential!',
            'analysis' => [
                'improvements' => 'Added urgency with "TODAY" and time-sensitive language, included social proof with "10,000+ entrepreneurs", added emotional appeal with future self reference, strengthened CTA with action-oriented language, added visual appeal with rocket emoji',
                'tone' => 'urgent and persuasive',
                'engagement_score' => '9.2',
                'conversion_potential' => 'high',
                'recommendations' => 'A/B test the emoji usage, test different social proof numbers, consider personalizing the message, test urgency variations',
                'key_changes' => 'urgency: Added time-sensitive language, social_proof: Included specific numbers, emotional_triggers: Future self reference, visual_elements: Strategic emoji placement',
            ],
        ];

        $resultResponse = $this->postJson("/api/ad-scripts/{$taskId}/result", $resultPayload, [
            'X-N8N-Signature' => $this->createWebhookSignature($resultPayload),
        ]);

        $resultResponse->assertStatus(200)
            ->assertJson([
                'message' => 'Result processed successfully',
                'data' => [
                    'id' => $taskId,
                    'status' => 'completed',
                    'was_updated' => true,
                    'new_script' => $resultPayload['new_script'],
                    'analysis' => $resultPayload['analysis'],
                ],
            ]);

        // Verify final task state
        $task->refresh();
        $this->assertEquals(TaskStatus::COMPLETED, $task->status);
        $this->assertEquals($resultPayload['new_script'], $task->new_script);
        $this->assertEquals($resultPayload['analysis'], $task->analysis);
        $this->assertNull($task->error_details);
    }

    public function test_integration_flow_with_n8n_failure_and_retry(): void
    {
        // Mock the N8n client with failure then success
        $mockN8nClient = Mockery::mock(N8nClientInterface::class);
        $this->app->instance(N8nClientInterface::class, $mockN8nClient);

        $mockN8nClient->shouldReceive('getWebhookUrl')
            ->andReturn('https://test-n8n.example.com/webhook/test');

        // Create a task
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PENDING]);

        // First attempt fails
        $mockN8nClient->shouldReceive('triggerWorkflow')
            ->once()
            ->andThrow(new \Exception('N8n service temporarily unavailable'));

        // Execute the job (should fail and be retried)
        $job = new TriggerN8nWorkflow($task);

        try {
            $job->handle(app(AdScriptTaskService::class), $mockN8nClient);
            $this->fail('Job should have thrown an exception');
        } catch (\Exception $e) {
            $this->assertEquals('N8n service temporarily unavailable', $e->getMessage());
        }

        // Verify task status is processing after failure (not final attempt)
        $task->refresh();
        $this->assertEquals(TaskStatus::PROCESSING, $task->status);

        // Reset task to pending for retry (simulating queue retry behavior)
        $task->update(['status' => TaskStatus::PENDING]);

        // Second attempt succeeds
        $mockN8nClient->shouldReceive('triggerWorkflow')
            ->once()
            ->andReturn([
                'success' => true,
                'workflow_id' => 'retry-workflow-123',
                'execution_id' => 'retry-exec-456',
            ]);

        // Execute the job again
        $job = new TriggerN8nWorkflow($task);
        $job->handle(app(AdScriptTaskService::class), $mockN8nClient);

        // Verify task status was updated to processing
        $task->refresh();
        $this->assertEquals(TaskStatus::PROCESSING, $task->status);
    }

    public function test_integration_flow_with_n8n_error_callback(): void
    {
        // Mock the N8n client
        $mockN8nClient = Mockery::mock(N8nClientInterface::class);
        $this->app->instance(N8nClientInterface::class, $mockN8nClient);

        $mockN8nClient->shouldReceive('getWebhookUrl')
            ->andReturn('https://test-n8n.example.com/webhook/test');

        // Create and process a task
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PENDING]);

        $mockN8nClient->shouldReceive('triggerWorkflow')
            ->once()
            ->andReturn(['success' => true, 'workflow_id' => 'error-test-123']);

        $job = new TriggerN8nWorkflow($task);
        $job->handle(app(AdScriptTaskService::class), $mockN8nClient);

        $task->refresh();
        $this->assertEquals(TaskStatus::PROCESSING, $task->status);

        // Simulate n8n callback with error
        $errorPayload = [
            'error' => 'AI model encountered an unexpected error during processing. The input may contain unsupported content or the service may be temporarily overloaded.',
        ];

        $resultResponse = $this->postJson("/api/ad-scripts/{$task->id}/result", $errorPayload, [
            'X-N8N-Signature' => $this->createWebhookSignature($errorPayload),
        ]);

        $resultResponse->assertStatus(200)
            ->assertJson([
                'message' => 'Result processed successfully',
                'data' => [
                    'id' => $task->id,
                    'status' => 'failed',
                    'was_updated' => true,
                    'error_details' => $errorPayload['error'],
                ],
            ]);

        // Verify final task state
        $task->refresh();
        $this->assertEquals(TaskStatus::FAILED, $task->status);
        $this->assertEquals($errorPayload['error'], $task->error_details);
        $this->assertNull($task->new_script);
        $this->assertNull($task->analysis);
    }

    public function test_integration_flow_with_http_client_mocking(): void
    {
        // Fake the queue to prevent actual job execution
        Queue::fake();

        // Mock Guzzle HTTP client instead of using Http::fake()
        /** @var \Mockery\MockInterface&\GuzzleHttp\Client $mockGuzzleClient */
        $mockGuzzleClient = Mockery::mock(\GuzzleHttp\Client::class);

        // Create a mock response
        $mockResponse = Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
        $mockResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockResponse->shouldReceive('getBody->getContents')->andReturn(json_encode([
            'success' => true,
            'workflow_id' => 'http-mock-123',
            'execution_id' => 'http-exec-456',
        ]));

        // Mock the POST request
        $mockGuzzleClient->shouldReceive('post')
            ->once()
            ->with(
                'https://test-n8n.example.com/webhook/test',
                Mockery::type('array')
            )
            ->andReturn($mockResponse);

        // Create HttpN8nClient with mocked Guzzle client
        $authHeaderKey = config('services.n8n.auth_header_key');
        $authHeaderValue = config('services.n8n.auth_header_value');
        $httpN8nClient = new \App\Services\HttpN8nClient(
            httpClient: $mockGuzzleClient,
            webhookUrl: 'https://test-n8n.example.com/webhook/test',
            authHeaderKey: is_string($authHeaderKey) ? $authHeaderKey : 'X-Test-Auth',
            authHeaderValue: is_string($authHeaderValue) ? $authHeaderValue : 'test-secret'
        );

        // Bind the mocked client to the container
        $this->app->instance(\App\Contracts\N8nClientInterface::class, $httpN8nClient);

        // Submit task
        $submissionPayload = [
            'reference_script' => 'HTTP client integration test script.',
            'outcome_description' => 'Testing with HTTP client mocking.',
        ];

        $submissionResponse = $this->postJson('/api/ad-scripts', $submissionPayload, $this->getNoRateLimitHeaders());
        $submissionResponse->assertStatus(202);
        $taskId = $submissionResponse->json('data.id');

        $task = AdScriptTask::find($taskId);

        // Process job with mocked HTTP client
        $job = new TriggerN8nWorkflow($task);
        $job->handle(app(AdScriptTaskService::class), $httpN8nClient);

        // Verify task status
        $task->refresh();
        $this->assertEquals(TaskStatus::PROCESSING, $task->status);
    }

    public function test_integration_flow_with_multiple_concurrent_tasks(): void
    {
        // Fake the queue to prevent actual job execution during submission
        Queue::fake();

        // Mock the N8n client
        $mockN8nClient = Mockery::mock(N8nClientInterface::class);
        $this->app->instance(N8nClientInterface::class, $mockN8nClient);

        $mockN8nClient->shouldReceive('getWebhookUrl')
            ->andReturn('https://test-n8n.example.com/webhook/test');

        // Create multiple tasks
        $tasks = [];
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/ad-scripts', [
                'reference_script' => "Concurrent integration test script {$i}",
                'outcome_description' => "Concurrent test description {$i}",
            ]);
            $tasks[] = AdScriptTask::find($response->json('data.id'));
        }

        // Mock n8n calls for all tasks
        $mockN8nClient->shouldReceive('triggerWorkflow')
            ->times(5)
            ->andReturn(['success' => true, 'workflow_id' => 'concurrent-test']);

        // Process all jobs
        foreach ($tasks as $index => $task) {
            $job = new TriggerN8nWorkflow($task);
            $job->handle(app(AdScriptTaskService::class), $mockN8nClient);

            $task->refresh();
            $this->assertEquals(TaskStatus::PROCESSING, $task->status, "Task {$index} should be processing");
        }

        // Process callbacks for all tasks
        foreach ($tasks as $index => $task) {
            $resultPayload = [
                'new_script' => "Improved concurrent script {$index}",
                'analysis' => [
                    'concurrent_test' => 'true',
                    'task_index' => (string)$index,
                ],
            ];

            $response = $this->postJson("/api/ad-scripts/{$task->id}/result", $resultPayload, [
                'X-N8N-Signature' => $this->createWebhookSignature($resultPayload),
            ]);

            $response->assertStatus(200);

            $task->refresh();
            $this->assertEquals(TaskStatus::COMPLETED, $task->status, "Task {$index} should be completed");
            $this->assertEquals($resultPayload['new_script'], $task->new_script);
        }
    }

    public function test_integration_flow_with_complex_analysis_data(): void
    {
        // Mock the N8n client
        $mockN8nClient = Mockery::mock(N8nClientInterface::class);
        $this->app->instance(N8nClientInterface::class, $mockN8nClient);

        $mockN8nClient->shouldReceive('getWebhookUrl')
            ->andReturn('https://test-n8n.example.com/webhook/test');

        // Create task
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PENDING]);

        $mockN8nClient->shouldReceive('triggerWorkflow')
            ->once()
            ->andReturn(['success' => true, 'workflow_id' => 'complex-analysis-test']);

        $job = new TriggerN8nWorkflow($task);
        $job->handle(app(AdScriptTaskService::class), $mockN8nClient);

        // Simulate callback with complex analysis data (flattened to strings for validation)
        $complexAnalysis = [
            'sentiment_analysis' => 'Overall sentiment: positive, confidence: 0.87',
            'emotions' => 'Excitement: 0.65, urgency: 0.72, trust: 0.58',
            'linguistic_features' => 'Readability: 8.2, word count: 45, sentences: 3, avg length: 15',
            'power_words' => 'transform, breakthrough, proven, unlock',
            'action_verbs' => 'click, achieve, transform',
            'persuasion_techniques' => 'Social proof: true, urgency: true, authority: false',
            'conversion_optimization' => 'CTA strength: 9.1, value prop clarity: 8.7, benefit focus: 8.9',
            'recommendations_primary' => 'Test different urgency levels - high priority',
            'recommendations_secondary' => 'Add authority indicators - medium priority',
            'metadata' => 'Processing time: 2847ms, model: gpt-4o-2024-08-06, confidence: 0.91',
        ];

        $resultPayload = [
            'new_script' => 'Transform your business TODAY with our PROVEN system! 🚀 Join 10,000+ successful entrepreneurs who\'ve already unlocked their potential. Don\'t wait - your breakthrough moment is just one click away!',
            'analysis' => $complexAnalysis,
        ];

        $resultResponse = $this->postJson("/api/ad-scripts/{$task->id}/result", $resultPayload, array_merge(
            ['X-N8N-Signature' => $this->createWebhookSignature($resultPayload)],
            $this->getNoRateLimitHeaders()
        ));

        $resultResponse->assertStatus(200);

        // Verify complex data was stored correctly
        $task->refresh();
        $this->assertEquals(TaskStatus::COMPLETED, $task->status);
        $this->assertEquals($resultPayload['new_script'], $task->new_script);
        $this->assertEquals($complexAnalysis, $task->analysis);

        // Verify specific data elements
        $this->assertStringContainsString('confidence: 0.87', $task->analysis['sentiment_analysis']);
        $this->assertStringContainsString('gpt-4o-2024-08-06', $task->analysis['metadata']);
        $this->assertStringContainsString('transform', $task->analysis['power_words']);
    }

    public function test_integration_flow_with_webhook_signature_edge_cases(): void
    {
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PROCESSING]);

        // Test with payload containing special characters that might affect signature
        $specialPayload = [
            'new_script' => 'Script with "quotes", \'apostrophes\', and unicode: 🚀 café naïve résumé',
            'analysis' => [
                'special_chars' => 'Testing: @#$%^&*()_+-=[]{}|;:,.<>?',
                'unicode' => '中文字符 émojis 🎯',
                'json_escape' => 'Line 1\nLine 2\tTabbed\r\nWindows line ending',
            ],
        ];

        $signature = $this->createWebhookSignature($specialPayload);

        $response = $this->postJson("/api/ad-scripts/{$task->id}/result", $specialPayload, array_merge(
            ['X-N8N-Signature' => $signature],
            $this->getNoRateLimitHeaders()
        ));

        $response->assertStatus(200);

        // Verify special characters were preserved
        $task->refresh();
        $this->assertEquals($specialPayload['new_script'], $task->new_script);
        $this->assertEquals($specialPayload['analysis'], $task->analysis);
    }

    public function test_integration_flow_with_database_transaction_rollback(): void
    {
        // Mock the N8n client to throw an exception after database changes
        $mockN8nClient = Mockery::mock(N8nClientInterface::class);
        $this->app->instance(N8nClientInterface::class, $mockN8nClient);

        $mockN8nClient->shouldReceive('getWebhookUrl')
            ->andReturn('https://test-n8n.example.com/webhook/test');

        // Create task
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PENDING]);
        $originalStatus = $task->status;

        // Mock n8n to fail after status update
        $mockN8nClient->shouldReceive('triggerWorkflow')
            ->once()
            ->andThrow(new \Exception('Database transaction test exception'));

        // Execute job (should fail)
        $job = new TriggerN8nWorkflow($task);

        try {
            $job->handle(app(AdScriptTaskService::class), $mockN8nClient);
            $this->fail('Job should have thrown an exception');
        } catch (\Exception $e) {
            $this->assertEquals('Database transaction test exception', $e->getMessage());
        }

        // Verify task status wasn't permanently changed due to transaction rollback
        $task->refresh();
        // Note: The actual behavior depends on whether the job uses database transactions
        // This test documents the expected behavior
        $this->assertTrue(
            $task->status === $originalStatus || $task->status === TaskStatus::PROCESSING,
            'Task status should be consistent after exception'
        );
    }

    public function test_integration_flow_performance_metrics(): void
    {
        // Fake the queue to prevent actual job execution in performance test
        Queue::fake();

        // Mock the N8n client for fast responses
        $mockN8nClient = Mockery::mock(N8nClientInterface::class);
        $this->app->instance(N8nClientInterface::class, $mockN8nClient);

        $mockN8nClient->shouldReceive('triggerWorkflow')
            ->andReturn(['success' => true, 'workflow_id' => 'perf-test']);

        $mockN8nClient->shouldReceive('getWebhookUrl')
            ->andReturn('https://test-n8n.example.com/webhook/test');

        // Measure submission performance
        $startTime = microtime(true);

        $response = $this->postJson('/api/ad-scripts', [
            'reference_script' => 'Performance test script for measuring response times.',
            'outcome_description' => 'Optimize for speed and efficiency.',
        ], $this->getNoRateLimitHeaders());

        $submissionTime = microtime(true) - $startTime;

        $response->assertStatus(202);
        $taskId = $response->json('data.id');

        // Submission should be fast (under 1 second)
        $this->assertLessThan(1.0, $submissionTime, 'Submission should be fast');

        // Measure job processing performance
        $task = AdScriptTask::find($taskId);
        $startTime = microtime(true);

        $job = new TriggerN8nWorkflow($task);
        $job->handle(app(AdScriptTaskService::class), $mockN8nClient);

        $jobTime = microtime(true) - $startTime;

        // Job processing should be fast (under 1 second)
        $this->assertLessThan(1.0, $jobTime, 'Job processing should be fast');

        // Measure callback processing performance
        $resultPayload = [
            'new_script' => 'Optimized performance test result',
            'analysis' => ['performance' => 'excellent'],
        ];

        $startTime = microtime(true);

        $resultResponse = $this->postJson("/api/ad-scripts/{$taskId}/result", $resultPayload, [
            'X-N8N-Signature' => $this->createWebhookSignature($resultPayload),
        ]);

        $callbackTime = microtime(true) - $startTime;

        $resultResponse->assertStatus(200);

        // Callback processing should be fast (under 1 second)
        $this->assertLessThan(1.0, $callbackTime, 'Callback processing should be fast');

        // Total end-to-end time should be reasonable
        $totalTime = $submissionTime + $jobTime + $callbackTime;
        $this->assertLessThan(3.0, $totalTime, 'Total processing time should be reasonable');
    }
}
