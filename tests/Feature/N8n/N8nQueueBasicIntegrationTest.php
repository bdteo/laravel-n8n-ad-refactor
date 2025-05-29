<?php

declare(strict_types=1);

namespace Tests\Feature\N8n;

use App\Contracts\AdScriptTaskServiceInterface;
use App\Contracts\N8nClientInterface;
use App\DTOs\N8nWebhookPayload;
use App\Enums\TaskStatus;
use App\Jobs\TriggerN8nWorkflow;
use App\Models\AdScriptTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Tests for basic N8n queue integration.
 *
 * These tests focus on the basic queue integration with n8n,
 * ensuring jobs are processed correctly.
 */
class N8nQueueBasicIntegrationTest extends TestCase
{
    // Make sure database is set up for tests
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up test configuration
        config([
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
     * Test queue integration with real job processing using mocked N8n client.
     */
    public function test_queue_integration_with_real_job_processing(): void
    {
        // Create a real task with PENDING status from the factory so it behaves properly
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::PENDING,
            'reference_script' => 'Test reference script',
            'outcome_description' => 'Test outcome description',
        ]);

        // Create a mock N8n client that always succeeds
        $mockN8nClient = Mockery::mock(N8nClientInterface::class);
        $mockN8nClient->shouldReceive('getWebhookUrl')
            ->zeroOrMoreTimes()
            ->andReturn('https://test.n8n.io/webhook/test');

        $mockN8nClient->shouldReceive('triggerWorkflow')
            ->once()
            ->andReturn([
                'success' => true,
                'workflow_id' => 'queue-test',
                'execution_id' => 'exec-queue-123',
            ]);

        $this->app->instance(N8nClientInterface::class, $mockN8nClient);

        // Process the job synchronously with real dependencies
        Queue::fake();

        // Use the actual task in the job instead of a mocked payload
        $job = new TriggerN8nWorkflow($task);

        // Mock the AdScriptTaskService
        $mockAdScriptTaskService = Mockery::mock(AdScriptTaskServiceInterface::class);
        $mockAdScriptTaskService->shouldReceive('canProcess')
            ->zeroOrMoreTimes()
            ->with(Mockery::type(AdScriptTask::class))
            ->andReturn(true);

        // Also mock the createWebhookPayload method
        $mockAdScriptTaskService->shouldReceive('createWebhookPayload')
            ->zeroOrMoreTimes()
            ->andReturn(new N8nWebhookPayload((string)$task->id, 'Test script', 'Test outcome', 'Test reference'));

        // Properly mock the markAsProcessing method to work with the real model
        $mockAdScriptTaskService->shouldReceive('markAsProcessing')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function ($taskObj) {
                $taskObj->markAsProcessing();

                return true;
            });

        // Bind the service to the container
        $this->app->instance(AdScriptTaskServiceInterface::class, $mockAdScriptTaskService);

        // Execute the job directly
        $job->handle();

        // Verify the job was processed
        Queue::assertNothingPushed();

        // The task should now be in PROCESSING status
        $task->refresh(); // Make sure we get the latest state from the database
        $this->assertEquals(TaskStatus::PROCESSING, $task->status);
    }
}
