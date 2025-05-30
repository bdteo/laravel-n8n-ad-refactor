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
use Mockery;
use Tests\TestCase;

/**
 * Simple test for N8n queue integration.
 */
class N8nQueueSimpleTest extends TestCase
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
     * Test successful job processing.
     */
    public function test_successful_job_processing(): void
    {
        // Create a real task with PENDING status
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::PENDING,
            'reference_script' => 'Test reference script',
            'outcome_description' => 'Test outcome description',
        ]);

        // Create a job with the task
        $job = new TriggerN8nWorkflow($task);

        // Mock the N8n client for successful response
        /** @var N8nClientInterface&\Mockery\MockInterface $mockN8nClient */
        $mockN8nClient = Mockery::mock(N8nClientInterface::class);
        // @phpstan-ignore-next-line
        $mockN8nClient->shouldReceive('getWebhookUrl')
            ->zeroOrMoreTimes()
            ->andReturn('https://test.n8n.io/webhook/test');
        // @phpstan-ignore-next-line
        $mockN8nClient->shouldReceive('triggerWorkflow')
            ->zeroOrMoreTimes()
            ->andReturn([
                'success' => true,
                'workflow_id' => 'test-workflow',
                'execution_id' => 'exec-123',
            ]);

        // Mock the AdScriptTaskService
        /** @var AdScriptTaskServiceInterface&\Mockery\MockInterface $mockAdScriptTaskService */
        $mockAdScriptTaskService = Mockery::mock(AdScriptTaskServiceInterface::class);
        // @phpstan-ignore-next-line
        $mockAdScriptTaskService->shouldReceive('canProcess')
            ->zeroOrMoreTimes()
            ->andReturn(true);
        // @phpstan-ignore-next-line
        $mockAdScriptTaskService->shouldReceive('createWebhookPayload')
            ->zeroOrMoreTimes()
            ->andReturn(new N8nWebhookPayload((string)$task->id, 'Test script', 'Test outcome'));
        // @phpstan-ignore-next-line
        $mockAdScriptTaskService->shouldReceive('markAsProcessing')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function ($taskObj) {
                $taskObj->markAsProcessing();

                return true;
            });
        // @phpstan-ignore-next-line
        $mockAdScriptTaskService->shouldReceive('markAsFailed')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function ($taskObj, $errorMessage) {
                $taskObj->markAsFailed($errorMessage);

                return true;
            });
        // @phpstan-ignore-next-line
        $mockAdScriptTaskService->shouldReceive('updateTaskWithJobInfo')
            ->zeroOrMoreTimes()
            ->andReturn(true);

        // Inject our mocks directly into the job
        $job->setAdScriptTaskService($mockAdScriptTaskService);
        $job->setN8nClient($mockN8nClient);

        // Execute the job
        $job->handle();

        // Verify the task status changed from PENDING to PROCESSING
        $task->refresh();
        $this->assertEquals(TaskStatus::PROCESSING, $task->status);
    }
}
