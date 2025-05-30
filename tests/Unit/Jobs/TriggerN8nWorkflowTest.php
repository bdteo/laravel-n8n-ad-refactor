<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Contracts\AdScriptTaskServiceInterface;
use App\Contracts\N8nClientInterface;
use App\DTOs\N8nWebhookPayload;
use App\Enums\TaskStatus;
use App\Exceptions\N8nClientException;
use App\Jobs\TriggerN8nWorkflow;
use App\Models\AdScriptTask;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TriggerN8nWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_has_correct_configuration(): void
    {
        // Create a model and job for the test
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PENDING]);

        // Create simple mocks that won't be used
        $service = Mockery::mock(AdScriptTaskServiceInterface::class);
        $n8nClient = Mockery::mock(N8nClientInterface::class);

        // Create the job
        $job = new TriggerN8nWorkflow($task, $service, $n8nClient);

        // Assert on job configuration
        $this->assertEquals(3, $job->tries);
        $this->assertEquals([10, 30, 60], $job->backoff);

        // Pass test
        $this->assertTrue(true);
    }

    public function test_handle_successfully_triggers_n8n_workflow(): void
    {
        // Create a task for the test with standard factory data
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::PENDING,
            'reference_script' => 'Test reference script',
            'outcome_description' => 'Test outcome description',
        ]);

        // Create mock service with expectations
        $service = Mockery::mock(AdScriptTaskServiceInterface::class);

        // Set expectations for the service mock
        $service->shouldReceive('canProcess')
            ->once()
            ->with($task)
            ->andReturn(true);

        $service->shouldReceive('markAsProcessing')
            ->once()
            ->with($task)
            ->andReturn(true);

        $service->shouldReceive('createWebhookPayload')
            ->once()
            ->with($task)
            ->andReturn(new N8nWebhookPayload(
                taskId: $task->id,
                referenceScript: $task->reference_script,
                outcomeDescription: $task->outcome_description
            ));

        // Create n8n client mock
        $n8nClient = Mockery::mock(N8nClientInterface::class);
        $n8nClient->shouldReceive('getWebhookUrl')
            ->andReturn('https://n8n.example.com/webhook/abc123');

        $n8nClient->shouldReceive('triggerWorkflow')
            ->once()
            ->andReturn(['success' => true, 'workflow_id' => 'test-workflow-123']);

        // Create the job
        $job = new TriggerN8nWorkflow($task, $service, $n8nClient);

        // Execute the job
        $job->handle();

        // Mockery will verify expectations were met
    }

    public function test_handle_skips_when_task_cannot_be_processed(): void
    {
        // Create a task with a non-processable status (e.g., PROCESSING instead of PENDING)
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PROCESSING]);

        // Create mock service with expectations
        $service = Mockery::mock(AdScriptTaskServiceInterface::class);

        // Set expectation that canProcess will return false for this task
        $service->shouldReceive('canProcess')
            ->once()
            ->with($task)
            ->andReturn(false);

        // Expect markAsFailed to be called when task cannot be processed
        $service->shouldReceive('markAsFailed')
            ->once()
            ->withArgs(function ($actualTask, $errorMessage) use ($task) {
                return $actualTask->id === $task->id &&
                       $errorMessage === 'Task cannot be processed: invalid status';
            })
            ->andReturn(true);

        // markAsProcessing should never be called when canProcess returns false
        $service->shouldNotReceive('markAsProcessing');

        // createWebhookPayload should never be called when canProcess returns false
        $service->shouldNotReceive('createWebhookPayload');

        // Create n8n client mock - it should not be used
        $n8nClient = Mockery::mock(N8nClientInterface::class);

        // We need to mock getWebhookUrl because it's called in the logJobStart method
        $n8nClient->shouldReceive('getWebhookUrl')
            ->andReturn('https://n8n.example.com/webhook/abc123');

        // triggerWorkflow should never be called
        $n8nClient->shouldNotReceive('triggerWorkflow');

        // Create the job
        $job = new TriggerN8nWorkflow($task, $service, $n8nClient);

        // This test specifically tests that the right exception is thrown
        // with the correct message when the task cannot be processed
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Task cannot be processed: invalid status');

        // Execute the job - should throw an exception
        $job->handle();
    }

    /** @test */
    public function marking_as_processing_fails_when_status_cannot_be_updated()
    {
        // Create a task with a valid status for processing
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PENDING]);

        // Create mock service with expectations
        $service = Mockery::mock(AdScriptTaskServiceInterface::class);

        // Add expectation for canProcess which is called first in real execution
        $service->shouldReceive('canProcess')
            ->with($task)
            ->andReturn(true);

        $service->shouldReceive('markAsProcessing')
            ->once()
            ->with($task)
            ->andReturn(false);

        // markAsFailed should be called when markAsProcessing fails
        $service->shouldReceive('markAsFailed')
            ->once()
            ->withArgs(function ($actualTask, $errorMessage) use ($task) {
                return $actualTask->id === $task->id &&
                       $errorMessage === 'Failed to mark task as processing';
            })
            ->andReturn(true);

        // Create the job with reflection to access private methods
        $n8nClient = Mockery::mock(N8nClientInterface::class);
        // Add expectation for getWebhookUrl which is called in logJobStart
        $n8nClient->shouldReceive('getWebhookUrl')
            ->andReturn('https://n8n.example.com/webhook/abc123');

        $job = new TriggerN8nWorkflow($task, $service, $n8nClient);

        // Use reflection to call the private markTaskAsProcessing method
        $method = new \ReflectionMethod(TriggerN8nWorkflow::class, 'markTaskAsProcessing');
        $method->setAccessible(true);

        // Assert that calling the method throws the expected exception
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to mark task as processing');

        // Call the method that should throw the exception
        $method->invoke($job, $service);
    }

    /** @test */
    public function n8n_client_exceptions_are_handled_appropriately()
    {
        // Create a task with a valid status for processing
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PENDING]);

        // Create mock service with expectations
        $service = Mockery::mock(AdScriptTaskServiceInterface::class);

        // Set expectation that canProcess will return true for this task
        $service->shouldReceive('canProcess')
            ->once()
            ->with($task)
            ->andReturn(true);

        // markAsProcessing will succeed
        $service->shouldReceive('markAsProcessing')
            ->once()
            ->with($task)
            ->andReturn(true);

        // Create webhook payload
        $service->shouldReceive('createWebhookPayload')
            ->once()
            ->with($task)
            ->andReturn(new N8nWebhookPayload(
                taskId: $task->id,
                referenceScript: $task->reference_script,
                outcomeDescription: $task->outcome_description
            ));

        // Expect markAsFailed to be called if this is the final attempt
        // But we're simulating a non-final attempt, so it should NOT be called
        $service->shouldReceive('markAsFailed')
            ->never();

        // Create n8n client mock
        $n8nClient = Mockery::mock(N8nClientInterface::class);
        $n8nClient->shouldReceive('getWebhookUrl')
            ->andReturn('https://n8n.example.com/webhook/abc123');

        // N8n client should throw an exception
        $n8nClient->shouldReceive('triggerWorkflow')
            ->once()
            ->andThrow(new N8nClientException('Test exception'));

        // Create the job and mock the attempts method to simulate a non-final retry
        $job = Mockery::mock(TriggerN8nWorkflow::class, [$task, $service, $n8nClient])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $job->shouldReceive('attempts')
            ->andReturn(1); // Not the final attempt

        // API test simulation should be used
        config(['services.n8n.integration_test_mode' => false]);

        // Execute the job
        $job->handle();

        // Mockery will verify expectations were met
    }

    /** @test */
    public function tasks_are_marked_as_failed_on_final_attempt_with_n8n_exception()
    {
        // Create a task with a valid status for processing
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PENDING]);

        // Create mock service with expectations
        $service = Mockery::mock(AdScriptTaskServiceInterface::class);

        // Set expectation that canProcess will return true for this task
        $service->shouldReceive('canProcess')
            ->once()
            ->with($task)
            ->andReturn(true);

        // markAsProcessing will succeed
        $service->shouldReceive('markAsProcessing')
            ->once()
            ->with($task)
            ->andReturn(true);

        // Create webhook payload
        $service->shouldReceive('createWebhookPayload')
            ->once()
            ->with($task)
            ->andReturn(new N8nWebhookPayload(
                taskId: $task->id,
                referenceScript: $task->reference_script,
                outcomeDescription: $task->outcome_description
            ));

        // This is a final attempt, markAsFailed should be called with the right error message
        $service->shouldReceive('markAsFailed')
            ->once()
            ->withArgs(function ($actualTask, $errorMessage) use ($task) {
                return $actualTask->id === $task->id &&
                       strpos($errorMessage, "Failed to trigger n8n workflow after 3 attempts") === 0;
            })
            ->andReturn(true);

        // Create n8n client mock
        $n8nClient = Mockery::mock(N8nClientInterface::class);
        $n8nClient->shouldReceive('getWebhookUrl')
            ->andReturn('https://n8n.example.com/webhook/abc123');

        // N8n client should throw an exception
        $n8nException = new N8nClientException('Test n8n exception');
        $n8nClient->shouldReceive('triggerWorkflow')
            ->once()
            ->andThrow($n8nException);

        // Create the job and mock the attempts method to simulate the final retry
        $job = Mockery::mock(TriggerN8nWorkflow::class, [$task, $service, $n8nClient])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $job->shouldReceive('attempts')
            ->andReturn(3); // Final attempt (matches tries property)

        // API test simulation should be used
        config(['services.n8n.integration_test_mode' => false]);

        // Execute the job
        $job->handle();

        // Mockery will verify expectations were met
    }

    /** @test */
    public function tasks_are_marked_as_failed_on_final_attempt_with_generic_exception()
    {
        // Create a task with a valid status for processing
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PENDING]);

        // Create mock service with expectations
        $service = Mockery::mock(AdScriptTaskServiceInterface::class);

        // Set expectation that canProcess will return true for this task
        $service->shouldReceive('canProcess')
            ->once()
            ->with($task)
            ->andReturn(true);

        // markAsProcessing will succeed
        $service->shouldReceive('markAsProcessing')
            ->once()
            ->with($task)
            ->andReturn(true);

        // Create webhook payload
        $service->shouldReceive('createWebhookPayload')
            ->once()
            ->with($task)
            ->andReturn(new N8nWebhookPayload(
                taskId: $task->id,
                referenceScript: $task->reference_script,
                outcomeDescription: $task->outcome_description
            ));

        // This is a final attempt, markAsFailed should be called with the right error message
        $service->shouldReceive('markAsFailed')
            ->once()
            ->withArgs(function ($actualTask, $errorMessage) use ($task) {
                return $actualTask->id === $task->id &&
                       strpos($errorMessage, "Failed to trigger n8n workflow after 3 attempts") === 0;
            })
            ->andReturn(true);

        // Create n8n client mock
        $n8nClient = Mockery::mock(N8nClientInterface::class);
        $n8nClient->shouldReceive('getWebhookUrl')
            ->andReturn('https://n8n.example.com/webhook/abc123');

        // N8n client should throw a generic exception
        $genericException = new Exception('Test generic exception');
        $n8nClient->shouldReceive('triggerWorkflow')
            ->once()
            ->andThrow($genericException);

        // Create the job and mock the attempts method to simulate the final retry
        $job = Mockery::mock(TriggerN8nWorkflow::class, [$task, $service, $n8nClient])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $job->shouldReceive('attempts')
            ->andReturn(3); // Final attempt (matches tries property)

        // API test simulation should be used
        config(['services.n8n.integration_test_mode' => false]);

        // Execute the job
        $job->handle();

        // Mockery will verify expectations were met
    }

    /** @test */
    public function failed_method_marks_task_as_failed()
    {
        // Create a task with a valid status
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PENDING]);

        // Create mock service
        $service = Mockery::mock(AdScriptTaskServiceInterface::class);

        // Expect markAsFailed to be called with the task and an error message starting with "Job failed permanently"
        $service->shouldReceive('markAsFailed')
            ->once()
            ->withArgs(function ($actualTask, $errorMessage) use ($task) {
                return $actualTask->id === $task->id &&
                       strpos($errorMessage, 'Job failed permanently:') === 0;
            })
            ->andReturn(true);

        // Create n8n client mock
        $n8nClient = Mockery::mock(N8nClientInterface::class);

        // Create the job
        $job = new TriggerN8nWorkflow($task, $service, $n8nClient);

        // Create a test exception
        $exception = new Exception('Test exception in failed method');

        // Call the failed method
        $job->failed($exception);

        // Mockery will verify expectations were met
    }

    /** @test */
    public function throws_exception_with_correct_message_when_n8n_client_fails()
    {
        // Create a task with a valid status for processing
        $task = AdScriptTask::factory()->create(['status' => TaskStatus::PENDING]);

        // Create mock service with expectations
        $service = Mockery::mock(AdScriptTaskServiceInterface::class);

        // Set up basic expectations that are required for the test flow
        $service->shouldReceive('canProcess')
            ->once()
            ->with($task)
            ->andReturn(true);

        $service->shouldReceive('markAsProcessing')
            ->once()
            ->with($task)
            ->andReturn(true);

        $service->shouldReceive('createWebhookPayload')
            ->once()
            ->with($task)
            ->andReturn(new N8nWebhookPayload(
                taskId: $task->id,
                referenceScript: $task->reference_script,
                outcomeDescription: $task->outcome_description
            ));

        // This test is for the final attempt, so markAsFailed should be called
        $service->shouldReceive('markAsFailed')
            ->once()
            ->withArgs(function ($actualTask, $errorMessage) use ($task) {
                return $actualTask->id === $task->id &&
                       strpos($errorMessage, 'Failed to trigger n8n workflow after 3 attempts') === 0;
            })
            ->andReturn(true);

        // Create n8n client mock
        $n8nClient = Mockery::mock(N8nClientInterface::class);
        $n8nClient->shouldReceive('getWebhookUrl')
            ->andReturn('https://n8n.example.com/webhook/abc123');

        // Trigger a connection failed exception
        $n8nClient->shouldReceive('triggerWorkflow')
            ->once()
            ->andThrow(N8nClientException::connectionFailed(
                'https://n8n.example.com/webhook/abc123',
                'Connection refused'
            ));

        // Create the job and set it up for the final attempt
        $job = Mockery::mock(TriggerN8nWorkflow::class, [$task, $service, $n8nClient])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $job->shouldReceive('attempts')
            ->andReturn(3); // Final attempt

        // Set to integration test mode to ensure exceptions propagate
        config(['services.n8n.integration_test_mode' => true]);

        // Expect an exception to be thrown
        $this->expectException(N8nClientException::class);
        $this->expectExceptionMessage('Connection refused');

        // Execute the job
        $job->handle();
    }

    /** @test */
    public function skips_when_task_cannot_be_processed_with_webhook_payload()
    {
        // Create a webhook payload instead of a task
        $payload = new N8nWebhookPayload(
            taskId: '999', // Non-existent task ID (as string)
            referenceScript: 'Test reference script',
            outcomeDescription: 'Test outcome description'
        );

        // Create mock service and client
        $service = Mockery::mock(AdScriptTaskServiceInterface::class);
        $n8nClient = Mockery::mock(N8nClientInterface::class);

        // We should never try to call markAsFailed on the payload
        $service->shouldNotReceive('markAsFailed');

        // Webhook URL is needed for logging
        $n8nClient->shouldReceive('getWebhookUrl')
            ->andReturn('https://n8n.example.com/webhook/abc123');

        // Should call triggerWorkflow since ensureTaskCanBeProcessed is skipped for webhook payloads
        $n8nClient->shouldReceive('triggerWorkflow')
            ->once()
            ->andReturn(['success' => true, 'workflow_id' => 'test-workflow-123']);

        // Create the job
        $job = new TriggerN8nWorkflow($payload, $service, $n8nClient);

        // Execute the job - should not throw an exception
        $job->handle();

        // Pass the test if we get here
        $this->assertTrue(true);
    }
}
