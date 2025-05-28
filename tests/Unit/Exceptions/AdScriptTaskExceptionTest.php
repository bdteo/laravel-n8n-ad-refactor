<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use App\Exceptions\AdScriptTaskException;
use PHPUnit\Framework\TestCase;

class AdScriptTaskExceptionTest extends TestCase
{
    public function test_creation_failed_creates_exception_with_correct_message(): void
    {
        $reason = 'Database connection failed';
        $exception = AdScriptTaskException::creationFailed($reason);

        $this->assertInstanceOf(AdScriptTaskException::class, $exception);
        $this->assertEquals("Failed to create ad script task: {$reason}", $exception->getMessage());
    }

    public function test_creation_failed_with_previous_exception(): void
    {
        $reason = 'Database connection failed';
        $previous = new \Exception('Connection timeout');
        $exception = AdScriptTaskException::creationFailed($reason, $previous);

        $this->assertInstanceOf(AdScriptTaskException::class, $exception);
        $this->assertEquals("Failed to create ad script task: {$reason}", $exception->getMessage());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function test_processing_failed_creates_exception_with_task_id(): void
    {
        $taskId = 'task-123';
        $reason = 'Invalid payload';
        $exception = AdScriptTaskException::processingFailed($taskId, $reason);

        $this->assertInstanceOf(AdScriptTaskException::class, $exception);
        $this->assertEquals("Failed to process ad script task {$taskId}: {$reason}", $exception->getMessage());
    }

    public function test_not_found_creates_exception_with_task_id(): void
    {
        $taskId = 'task-456';
        $exception = AdScriptTaskException::notFound($taskId);

        $this->assertInstanceOf(AdScriptTaskException::class, $exception);
        $this->assertEquals("Ad script task with ID {$taskId} not found", $exception->getMessage());
    }

    public function test_invalid_state_transition_creates_exception_with_states(): void
    {
        $taskId = 'task-789';
        $currentState = 'completed';
        $targetState = 'processing';
        $exception = AdScriptTaskException::invalidStateTransition($taskId, $currentState, $targetState);

        $this->assertInstanceOf(AdScriptTaskException::class, $exception);
        $this->assertEquals(
            "Cannot transition task {$taskId} from {$currentState} to {$targetState}",
            $exception->getMessage()
        );
    }

    public function test_dispatch_failed_creates_exception_with_task_id(): void
    {
        $taskId = 'task-101';
        $reason = 'Queue is full';
        $exception = AdScriptTaskException::dispatchFailed($taskId, $reason);

        $this->assertInstanceOf(AdScriptTaskException::class, $exception);
        $this->assertEquals("Failed to dispatch ad script task {$taskId}: {$reason}", $exception->getMessage());
    }

    public function test_update_failed_creates_exception_with_task_id(): void
    {
        $taskId = 'task-202';
        $reason = 'Concurrent modification';
        $exception = AdScriptTaskException::updateFailed($taskId, $reason);

        $this->assertInstanceOf(AdScriptTaskException::class, $exception);
        $this->assertEquals("Failed to update ad script task {$taskId}: {$reason}", $exception->getMessage());
    }

    public function test_concurrent_modification_creates_exception_with_task_id(): void
    {
        $taskId = 'task-303';
        $exception = AdScriptTaskException::concurrentModification($taskId);

        $this->assertInstanceOf(AdScriptTaskException::class, $exception);
        $this->assertEquals("Concurrent modification detected for task {$taskId}", $exception->getMessage());
    }
}
