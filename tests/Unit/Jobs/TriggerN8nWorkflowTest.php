<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Contracts\N8nClientInterface;
use App\DTOs\N8nWebhookPayload;
use App\Enums\TaskStatus;
use App\Exceptions\N8nClientException;
use App\Jobs\TriggerN8nWorkflow;
use App\Models\AdScriptTask;
use App\Services\AdScriptTaskService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class TriggerN8nWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private AdScriptTask $task;
    /** @var AdScriptTaskService&MockInterface */
    private $service;
    /** @var N8nClientInterface&MockInterface */
    private $n8nClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->task = AdScriptTask::factory()->create([
            'status' => TaskStatus::PENDING,
        ]);

        /** @var AdScriptTaskService&MockInterface $service */
        $service = Mockery::mock(AdScriptTaskService::class);
        $this->service = $service;
        $this->app->instance(AdScriptTaskService::class, $this->service);

        /** @var N8nClientInterface&MockInterface $n8nClient */
        $n8nClient = Mockery::mock(N8nClientInterface::class);
        $this->n8nClient = $n8nClient;
        $this->app->instance(N8nClientInterface::class, $this->n8nClient);
    }

    public function test_job_has_correct_configuration(): void
    {
        $job = new TriggerN8nWorkflow($this->task);

        $this->assertEquals(3, $job->tries);
        $this->assertEquals([10, 30, 60], $job->backoff);
    }

    public function test_handle_successfully_triggers_n8n_workflow(): void
    {
        // Arrange
        $payload = new N8nWebhookPayload(
            $this->task->id,
            $this->task->reference_script,
            $this->task->outcome_description
        );

        $expectedResponse = ['status' => 'success'];

        $this->service->shouldReceive('canProcess')
            ->once()
            ->with($this->task)
            ->andReturn(true);

        $this->service->shouldReceive('markAsProcessing')
            ->once()
            ->with($this->task)
            ->andReturn(true);

        $this->service->shouldReceive('createWebhookPayload')
            ->once()
            ->with($this->task)
            ->andReturn($payload);

        $this->n8nClient->shouldReceive('getWebhookUrl')
            ->once()
            ->andReturn('https://test.n8n.io/webhook/test');

        $this->n8nClient->shouldReceive('triggerWorkflow')
            ->once()
            ->with($payload)
            ->andReturn($expectedResponse);

        Log::shouldReceive('info')->twice();

        // Act
        $job = new TriggerN8nWorkflow($this->task);
        $job->handle($this->service, $this->n8nClient);

        // Assert - expectations are verified by Mockery
        $this->assertTrue(true);
    }

    public function test_handle_skips_when_task_cannot_be_processed(): void
    {
        // Arrange
        $this->service->shouldReceive('canProcess')
            ->once()
            ->with($this->task)
            ->andReturn(false);

        $this->service->shouldNotReceive('markAsProcessing');
        $this->service->shouldNotReceive('createWebhookPayload');

        $this->n8nClient->shouldReceive('getWebhookUrl')
            ->once()
            ->andReturn('https://test.n8n.io/webhook/test');

        $this->n8nClient->shouldNotReceive('triggerWorkflow');

        Log::shouldReceive('info')->once();
        Log::shouldReceive('warning')->once();

        // Act
        $job = new TriggerN8nWorkflow($this->task);
        $job->handle($this->service, $this->n8nClient);

        // Assert - expectations are verified by Mockery
        $this->assertTrue(true);
    }

    public function test_handle_throws_exception_when_marking_as_processing_fails(): void
    {
        // Arrange
        $this->service->shouldReceive('canProcess')
            ->once()
            ->with($this->task)
            ->andReturn(true);

        $this->service->shouldReceive('markAsProcessing')
            ->once()
            ->with($this->task)
            ->andReturn(false);

        $this->n8nClient->shouldReceive('getWebhookUrl')
            ->once()
            ->andReturn('https://test.n8n.io/webhook/test');

        Log::shouldReceive('info')->once();
        Log::shouldReceive('error')->twice(); // Once for marking failure, once for exception

        // Act & Assert
        $job = new TriggerN8nWorkflow($this->task);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to mark task as processing');

        $job->handle($this->service, $this->n8nClient);
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

        $this->service->shouldReceive('canProcess')
            ->once()
            ->with($this->task)
            ->andReturn(true);

        $this->service->shouldReceive('markAsProcessing')
            ->once()
            ->with($this->task)
            ->andReturn(true);

        $this->service->shouldReceive('createWebhookPayload')
            ->once()
            ->with($this->task)
            ->andReturn($payload);

        $this->n8nClient->shouldReceive('getWebhookUrl')
            ->once()
            ->andReturn('https://test.n8n.io/webhook/test');

        $this->n8nClient->shouldReceive('triggerWorkflow')
            ->once()
            ->with($payload)
            ->andThrow($n8nException);

        Log::shouldReceive('info')->once();
        Log::shouldReceive('error')->once();

        // Act & Assert
        $job = new TriggerN8nWorkflow($this->task);

        $this->expectException(N8nClientException::class);

        $job->handle($this->service, $this->n8nClient);
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

        $this->service->shouldReceive('canProcess')
            ->once()
            ->with($this->task)
            ->andReturn(true);

        $this->service->shouldReceive('markAsProcessing')
            ->once()
            ->with($this->task)
            ->andReturn(true);

        $this->service->shouldReceive('createWebhookPayload')
            ->once()
            ->with($this->task)
            ->andReturn($payload);

        $this->service->shouldReceive('markAsFailed')
            ->once()
            ->with($this->task, Mockery::type('string'));

        $this->n8nClient->shouldReceive('getWebhookUrl')
            ->once()
            ->andReturn('https://test.n8n.io/webhook/test');

        $this->n8nClient->shouldReceive('triggerWorkflow')
            ->once()
            ->with($payload)
            ->andThrow($n8nException);

        Log::shouldReceive('info')->once();
        Log::shouldReceive('error')->once();

        // Act & Assert
        $job = new TriggerN8nWorkflow($this->task);
        $job->tries = 1; // Force it to be the final attempt

        $this->expectException(N8nClientException::class);

        $job->handle($this->service, $this->n8nClient);
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

        $this->service->shouldReceive('canProcess')
            ->once()
            ->with($this->task)
            ->andReturn(true);

        $this->service->shouldReceive('markAsProcessing')
            ->once()
            ->with($this->task)
            ->andReturn(true);

        $this->service->shouldReceive('createWebhookPayload')
            ->once()
            ->with($this->task)
            ->andReturn($payload);

        $this->service->shouldReceive('markAsFailed')
            ->once()
            ->with($this->task, Mockery::type('string'));

        $this->n8nClient->shouldReceive('getWebhookUrl')
            ->once()
            ->andReturn('https://test.n8n.io/webhook/test');

        $this->n8nClient->shouldReceive('triggerWorkflow')
            ->once()
            ->with($payload)
            ->andThrow($genericException);

        Log::shouldReceive('info')->once();
        Log::shouldReceive('error')->once();

        // Act & Assert
        $job = new TriggerN8nWorkflow($this->task);
        $job->tries = 1; // Force it to be the final attempt

        $this->expectException(Exception::class);

        $job->handle($this->service, $this->n8nClient);
    }

    public function test_failed_method_marks_task_as_failed(): void
    {
        // Arrange
        $exception = new Exception('Test failure');

        $this->service->shouldReceive('markAsFailed')
            ->once()
            ->with($this->task, 'Job failed permanently: Test failure')
            ->andReturn(true);

        Log::shouldReceive('error')->once();

        // Act
        $job = new TriggerN8nWorkflow($this->task);
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
