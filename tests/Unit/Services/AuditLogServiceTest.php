<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\TaskStatus;
use App\Models\AdScriptTask;
use App\Services\AuditLogService;
use Exception;
use Tests\TestCase;
use Tests\Traits\DisableLogs;

/**
 * @group unit
 */
class AuditLogServiceTest extends TestCase
{
    use DisableLogs;

    private AuditLogService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable logging to avoid conflicts with Log facade mocking
        $this->disableLogging();

        $this->service = app(AuditLogService::class);
    }

    public function test_log_task_creation(): void
    {
        // Arrange
        $task = $this->createMockTask();

        // Act - this shouldn't throw an exception
        $this->service->logTaskCreation($task);

        // Assert - we're just testing that the method runs without errors
        $this->assertTrue(true);
    }

    public function test_log_task_status_change(): void
    {
        // Arrange
        $task = $this->createMockTask();
        $oldStatus = TaskStatus::PENDING;
        $newStatus = TaskStatus::PROCESSING;

        // Act - this shouldn't throw an exception
        $this->service->logTaskStatusChange($task, $oldStatus, $newStatus);

        // Assert - we're just testing that the method runs without errors
        $this->assertTrue(true);
    }

    public function test_log_task_completed(): void
    {
        // Arrange
        $task = $this->createMockTask();
        $task->new_script = "new script content";
        $task->analysis = ['key' => 'value'];

        // Act - this shouldn't throw an exception
        $this->service->logTaskCompleted($task);

        // Assert - we're just testing that the method runs without errors
        $this->assertTrue(true);
    }

    public function test_log_task_failed(): void
    {
        // Arrange
        $task = $this->createMockTask();
        $errorDetails = 'Test error message';

        // Act - this shouldn't throw an exception
        $this->service->logTaskFailed($task, $errorDetails);

        // Assert - we're just testing that the method runs without errors
        $this->assertTrue(true);
    }

    public function test_log_error(): void
    {
        // Arrange
        $message = 'Test error message';
        $exception = new Exception('Test exception');
        $context = ['test' => 'value'];

        // Act - this shouldn't throw an exception
        $this->service->logError($message, $exception, $context);

        // Assert - we're just testing that the method runs without errors
        $this->assertTrue(true);
    }

    public function test_log_api_request(): void
    {
        // Arrange
        $endpoint = 'api/test';
        $context = ['ip' => '127.0.0.1'];

        // Act - this shouldn't throw an exception
        $this->service->logApiRequest($endpoint, $context);

        // Assert - we're just testing that the method runs without errors
        $this->assertTrue(true);
    }

    /**
     * Create a mock AdScriptTask for testing.
     */
    private function createMockTask(): AdScriptTask
    {
        $task = new AdScriptTask();
        $task->id = 'test-uuid';
        $task->reference_script = 'test script';
        $task->outcome_description = 'test outcome';
        $task->status = TaskStatus::PENDING;

        return $task;
    }
}
