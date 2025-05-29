<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Contracts\AdScriptTaskServiceInterface;
use App\Contracts\N8nClientInterface; // Added this line
// Removed N8nWebhookPayload import as we're using AdScriptTask directly
use App\DTOs\N8nWebhookPayload;
use App\Enums\TaskStatus;
use App\Exceptions\N8nClientException;
use App\Jobs\TriggerN8nWorkflow;
use App\Models\AdScriptTask;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\LogManager;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class TriggerN8nWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private AdScriptTask $task;
    private AdScriptTaskServiceInterface $service;
    /** @var N8nClientInterface&MockInterface */
    private $n8nClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->task = AdScriptTask::factory()->create(['status' => TaskStatus::PENDING]);

        /** @var AdScriptTaskServiceInterface&MockInterface $service */
        $service = Mockery::mock(AdScriptTaskServiceInterface::class);
        $this->service = $service;
        $this->app->instance(AdScriptTaskServiceInterface::class, $this->service);

        /** @var N8nClientInterface&MockInterface $n8nClient */
        $n8nClient = Mockery::mock(N8nClientInterface::class);
        $this->n8nClient = $n8nClient;
        $this->app->instance(N8nClientInterface::class, $this->n8nClient);

        // Mock the Log facade with a Mockery logger
        $mockLogger = \Mockery::mock(LogManager::class);
        $mockLogger->shouldReceive('info')->andReturn(null);
        $mockLogger->shouldReceive('debug')->andReturn(null);
        $mockLogger->shouldReceive('error')->andReturn(null);
        $mockLogger->shouldReceive('warning')->andReturn(null);
        \Illuminate\Support\Facades\Log::swap($mockLogger);
    }

    public function test_job_has_correct_configuration(): void
    {
        $job = new TriggerN8nWorkflow($this->task, $this->service, $this->n8nClient);

        $this->assertEquals(3, $job->tries);
        $this->assertEquals([10, 30, 60], $job->backoff);
    }

    public function test_handle_successfully_triggers_n8n_workflow(): void
    {
        // Set up a minimal test with only essential mock expectations
        $expectedPayload = N8nWebhookPayload::fromAdScriptTask($this->task);
        $expectedResponse = ['success' => true, 'status' => 'processing'];

        // Core expectations
        $this->service->shouldReceive('canProcess')->with($this->task)->andReturn(true)->once();
        $this->service->shouldReceive('markAsProcessing')->with($this->task)->andReturn(true)->once();
        $this->service->shouldReceive('createWebhookPayload')->with($this->task)->andReturn($expectedPayload)->once();
        $this->n8nClient->shouldReceive('triggerWorkflow')->with(Mockery::any())->andReturn($expectedResponse)->once();

        // Negative expectations
        $this->service->shouldNotReceive('markAsFailed');

        // Execute the job directly
        $job = new TriggerN8nWorkflow($this->task, $this->service, $this->n8nClient);
        $job->handle();

        // If we get here without exceptions, the test passes
        $this->assertTrue(true);
    }

    public function test_handle_skips_when_task_cannot_be_processed(): void
    {
        // Arrange
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Task cannot be processed: invalid status');

        $this->service->shouldReceive('canProcess')
            ->once()
            ->with($this->task)
            ->andReturn(false); // This will cause ensureTaskCanBeProcessed to throw

        $this->service->shouldReceive('markAsProcessing')->never();
        // createWebhookPayload is handled by global stub, but should not be called.
        $this->service->shouldReceive('createWebhookPayload')->never();
        $this->n8nClient->shouldReceive('triggerWorkflow')->never();

        // Act
        $job = new TriggerN8nWorkflow($this->task, $this->service, $this->n8nClient);
        $job->handle();
    }

    public function test_handle_throws_exception_when_marking_as_processing_fails(): void
    {
        // Arrange
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to mark task as processing');

        $this->service->shouldReceive('canBeProcessed')
            ->once()
            ->with($this->task)
            ->andReturn(true);

        $this->service->shouldReceive('markAsProcessing') // The service method
            ->once()
            ->with($this->task)
            ->andReturn(false); // This causes the job's markTaskAsProcessing helper to throw

        // createWebhookPayload is handled by global stub, but should not be called.
        $this->service->shouldReceive('createWebhookPayload')->never();
        $this->n8nClient->shouldReceive('triggerWorkflow')->never();

        // Act
        $job = new TriggerN8nWorkflow($this->task, $this->service, $this->n8nClient);
        $job->handle();
    }

    public function test_handle_handles_n8n_client_exception(): void
    {
        // Arrange
        $payload = new N8nWebhookPayload(
            $this->task->id,
            $this->task->reference_script,
            $this->task->outcome_description
        );

        $n8nException = N8nClientException::connectionFailed('https://test.n8n.io/webhook/test', 'Connection timeout');

        $this->service->shouldReceive('canBeProcessed')
            ->once()
            ->with($this->task)
            ->andReturn(true);

        $this->service->shouldReceive('markAsProcessing')
            ->once()
            ->with($this->task)
            ->andReturn(true);

        $expectedPayload = new \App\DTOs\N8nWebhookPayload($this->task->id, $this->task->reference_script, $this->task->outcome_description);
        $this->service->shouldReceive('createWebhookPayload')
            ->once()
            ->with($this->task)
            ->andReturn($expectedPayload);

        $this->n8nClient->shouldReceive('getWebhookUrl')
            ->once()
            ->andReturn('https://test.n8n.io/webhook/test');

        $this->n8nClient->shouldReceive('triggerWorkflow')
            ->once()
            ->with($expectedPayload)
            ->andThrow($n8nException);

        // Act & Assert
        $job = new TriggerN8nWorkflow($payload, $this->service, $this->n8nClient);

        $this->expectException(N8nClientException::class);

        $job->handle();
    }

    public function test_handle_marks_task_as_failed_on_final_attempt_with_n8n_exception(): void
    {
        // Arrange
        $payload = new N8nWebhookPayload(
            $this->task->id,
            $this->task->reference_script,
            $this->task->outcome_description
        );

        $n8nException = N8nClientException::connectionFailed('https://test.n8n.io/webhook/test', 'Connection timeout');

        $this->service->shouldReceive('canBeProcessed')
            ->once()
            ->with($this->task)
            ->andReturn(true);

        $this->service->shouldReceive('markAsProcessing')
            ->once()
            ->with($this->task)
            ->andReturn(true);

        $expectedPayload = new \App\DTOs\N8nWebhookPayload($this->task->id, $this->task->reference_script, $this->task->outcome_description);
        $this->service->shouldReceive('createWebhookPayload')
            ->once()
            ->with($this->task)
            ->andReturn($expectedPayload);

        $this->service->shouldReceive('markAsFailed')
            ->once()
            ->with($this->task, Mockery::type('string'));

        $this->n8nClient->shouldReceive('getWebhookUrl')
            ->once()
            ->andReturn('https://test.n8n.io/webhook/test');

        $this->n8nClient->shouldReceive('triggerWorkflow')
            ->once()
            ->with($expectedPayload)
            ->andThrow($n8nException);

        // Act & Assert
        $job = new TriggerN8nWorkflow($payload, $this->service, $this->n8nClient);
        $job->tries = 1; // Force it to be the final attempt

        $this->expectException(N8nClientException::class);

        $job->handle();
    }

    public function test_handle_marks_task_as_failed_on_final_attempt_with_generic_exception(): void
    {
        // Arrange
        $payload = new N8nWebhookPayload(
            $this->task->id,
            $this->task->reference_script,
            $this->task->outcome_description
        );

        $genericException = new Exception('Unexpected error');

        $this->service->shouldReceive('canBeProcessed')
            ->once()
            ->with($this->task)
            ->andReturn(true);

        $this->service->shouldReceive('markAsProcessing')
            ->once()
            ->with($this->task)
            ->andReturn(true);

        $expectedPayload = new \App\DTOs\N8nWebhookPayload($this->task->id, $this->task->reference_script, $this->task->outcome_description);
        $this->service->shouldReceive('createWebhookPayload')
            ->once()
            ->with($this->task)
            ->andReturn($expectedPayload);

        $this->service->shouldReceive('markAsFailed')
            ->once()
            ->with($this->task, Mockery::type('string'));

        $this->n8nClient->shouldReceive('getWebhookUrl')
            ->once()
            ->andReturn('https://test.n8n.io/webhook/test');

        $this->n8nClient->shouldReceive('triggerWorkflow')
            ->once()
            ->with($expectedPayload)
            ->andThrow($genericException);

        // Act & Assert
        $job = new TriggerN8nWorkflow($payload, $this->service, $this->n8nClient);
        $job->tries = 1; // Force it to be the final attempt

        $this->expectException(Exception::class);

        $job->handle();
    }

    public function test_failed_method_marks_task_as_failed(): void
    {
        // Arrange
        $exception = new Exception('Test failure');

        $this->service->shouldReceive('markAsFailed')
            ->once()
            ->with($this->task, 'Job failed permanently: Test failure')
            ->andReturn(true);

        // Act
        $job = new TriggerN8nWorkflow($this->task, $this->service, $this->n8nClient);
        $job->failed($exception);

        // Assert
        $this->assertTrue(true); // Expectations are verified by Mockery
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
