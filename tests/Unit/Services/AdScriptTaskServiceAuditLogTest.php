<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\DTOs\N8nResultPayload;
use App\Enums\TaskStatus;
use App\Models\AdScriptTask;
use App\Services\AdScriptTaskService;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class AdScriptTaskServiceAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private AdScriptTaskService $service;
    /** @var MockInterface */
    private $auditLogService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a mock of AuditLogService
        /** @var AuditLogService&MockInterface $auditLogMock */
        $auditLogMock = Mockery::mock(AuditLogService::class);
        $this->auditLogService = $auditLogMock;

        // Create an instance of AdScriptTaskService with our mock
        $this->service = new AdScriptTaskService($this->auditLogService);
    }

    public function test_create_task_logs_creation(): void
    {
        // Expect the audit log service to be called
        $this->auditLogService
            ->shouldReceive('logTaskCreation')
            ->once()
            ->withArgs(function (AdScriptTask $task) {
                return $task->reference_script === 'test script';
            });

        // Act - create a task
        $task = $this->service->createTask([
            'reference_script' => 'test script',
            'outcome_description' => 'test outcome',
        ]);

        // Assert
        $this->assertEquals('test script', $task->reference_script);
        $this->assertEquals('test outcome', $task->outcome_description);
    }

    public function test_process_success_result_logs_status_change_and_completion(): void
    {
        // Create a task
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::PROCESSING,
        ]);

        // Create a success payload using the static factory method
        $payload = N8nResultPayload::success('new script content', ['key' => 'value']);

        // Expect audit log calls
        $this->auditLogService
            ->shouldReceive('logTaskStatusChange')
            ->once()
            ->withArgs(function (AdScriptTask $logTask, TaskStatus $oldStatus, TaskStatus $newStatus) use ($task) {
                return
                    $logTask->id === $task->id &&
                    $oldStatus === TaskStatus::PROCESSING &&
                    $newStatus === TaskStatus::COMPLETED;
            });

        $this->auditLogService
            ->shouldReceive('logTaskCompleted')
            ->once()
            ->withArgs(function (AdScriptTask $logTask) use ($task) {
                return $logTask->id === $task->id;
            });

        // Act
        $result = $this->service->processSuccessResult($task, $payload);

        // Assert
        $this->assertTrue($result);
        $this->assertEquals(TaskStatus::COMPLETED, $task->status);
    }

    public function test_process_error_result_logs_status_change_and_failure(): void
    {
        // Create a task
        $task = AdScriptTask::factory()->create([
            'status' => TaskStatus::PROCESSING,
        ]);

        // Create an error payload using the static factory method
        $payload = N8nResultPayload::error('Error processing task');

        // Expect audit log calls
        $this->auditLogService
            ->shouldReceive('logTaskStatusChange')
            ->once()
            ->withArgs(function (AdScriptTask $logTask, TaskStatus $oldStatus, TaskStatus $newStatus) use ($task) {
                return
                    $logTask->id === $task->id &&
                    $oldStatus === TaskStatus::PROCESSING &&
                    $newStatus === TaskStatus::FAILED;
            });

        $this->auditLogService
            ->shouldReceive('logTaskFailed')
            ->once()
            ->withArgs(function (AdScriptTask $logTask, string $error) use ($task) {
                return
                    $logTask->id === $task->id &&
                    $error === 'Error processing task';
            });

        // Act
        $result = $this->service->processErrorResult($task, $payload);

        // Assert
        $this->assertTrue($result);
        $this->assertEquals(TaskStatus::FAILED, $task->status);
    }

    public function test_process_result_logs_webhook_receipt(): void
    {
        // Create a task
        $task = AdScriptTask::factory()->create();

        // Create a payload
        $payload = N8nResultPayload::success('new script content', []);

        // Expect webhook event to be logged
        $this->auditLogService
            ->shouldReceive('logWebhookEvent')
            ->once()
            ->withArgs(function (string $direction, AdScriptTask $logTask, array $context) use ($task) {
                return
                    $direction === 'received' &&
                    $logTask->id === $task->id &&
                    isset($context['payload_type']) &&
                    $context['has_new_script'] === true;
            });

        // Mock any additional calls that would happen
        $this->auditLogService->shouldReceive('logTaskStatusChange')->andReturn(null);
        $this->auditLogService->shouldReceive('logTaskCompleted')->andReturn(null);
        $this->auditLogService->shouldReceive('logIdempotentOperation')->andReturn(null);

        // Act
        $this->service->processResult($task, $payload);
    }

    public function test_process_result_idempotent_logs_api_response(): void
    {
        // Create a task
        $task = AdScriptTask::factory()->create();

        // Create a payload using the static factory method
        $payload = N8nResultPayload::success('new script content', []);

        // Mock all required calls
        $this->auditLogService->shouldReceive('logWebhookEvent')->andReturn(null);
        $this->auditLogService->shouldReceive('logTaskStatusChange')->andReturn(null);
        $this->auditLogService->shouldReceive('logTaskCompleted')->andReturn(null);

        // Expect API response to be logged
        $this->auditLogService
            ->shouldReceive('logApiResponse')
            ->once()
            ->withArgs(function (string $endpoint, int $statusCode, array $context) use ($task) {
                return
                    $endpoint === 'process_result' &&
                    isset($context['task_id']) &&
                    $context['task_id'] === $task->id;
            });

        // Act
        $this->service->processResultIdempotent($task, $payload);
    }
}
