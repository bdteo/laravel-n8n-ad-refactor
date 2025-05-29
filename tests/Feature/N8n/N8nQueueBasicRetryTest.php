<?php

declare(strict_types=1);

namespace Tests\Feature\N8n;

use App\Contracts\AdScriptTaskServiceInterface;
use App\Contracts\N8nClientInterface;
use App\DTOs\N8nWebhookPayload;
use App\Enums\TaskStatus;
use App\Exceptions\N8nClientException;
use App\Jobs\TriggerN8nWorkflow;
use App\Models\AdScriptTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * Basic retry test for N8n queue integration.
 */
class N8nQueueBasicRetryTest extends TestCase
{
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
            'services.n8n.integration_test_mode' => true,
        ]);

        Log::spy();
    }

    /**
     * Test a simple retry scenario.
     */
    public function test_simple_retry_scenario(): void
    {
        // Create a real task with PENDING status
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::PENDING,
            'reference_script' => 'Test reference script',
            'outcome_description' => 'Test outcome description',
        ]);

        // Create a job with the task
        $job = new TriggerN8nWorkflow($task);

        // Mock the N8n client
        $mockN8nClient = Mockery::mock(N8nClientInterface::class);
        $mockN8nClient->shouldReceive('getWebhookUrl')
            ->zeroOrMoreTimes()
            ->andReturn('https://test.n8n.io/webhook/test');

        $mockN8nClient->shouldReceive('triggerWorkflow')
            ->zeroOrMoreTimes()
            ->andThrow(new N8nClientException('Test exception'));

        // Mock the AdScriptTaskService
        $mockAdScriptTaskService = Mockery::mock(AdScriptTaskServiceInterface::class);

        // This is the critical part - ensure canProcess returns true
        $mockAdScriptTaskService->shouldReceive('canProcess')
            ->zeroOrMoreTimes()
            ->andReturn(true);

        $mockAdScriptTaskService->shouldReceive('createWebhookPayload')
            ->zeroOrMoreTimes()
            ->andReturn(new N8nWebhookPayload((string)$task->id, 'Test script', 'Test outcome', 'Test reference'));

        $mockAdScriptTaskService->shouldReceive('markAsProcessing')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function ($taskObj) {
                $taskObj->markAsProcessing();

                return true;
            });

        // Add expectation for markAsFailed method
        $mockAdScriptTaskService->shouldReceive('markAsFailed')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function ($taskObj, $errorMessage) {
                $taskObj->markAsFailed($errorMessage);

                return true;
            });

        // Inject our mocks directly into the job
        $job->adScriptTaskService = $mockAdScriptTaskService;
        $job->n8nClient = $mockN8nClient;

        // Execute the job and expect an exception
        try {
            $job->handle();
            $this->fail('Job should have thrown an exception');
        } catch (N8nClientException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        // Verify the task status changed from PENDING
        $task->refresh();
        $this->assertNotEquals(TaskStatus::PENDING, $task->status);
    }
}
