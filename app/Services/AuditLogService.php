<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\AdScriptTask;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AuditLogService
{
    /**
     * The name of the audit log channel.
     */
    private const CHANNEL = 'audit';

    /**
     * Log a task creation event.
     */
    public function logTaskCreation(AdScriptTask $task): void
    {
        $this->log('task.created', [
            'task_id' => $task->id,
            'reference_script_length' => strlen($task->reference_script),
            'outcome_description_length' => strlen($task->outcome_description),
            'status' => $task->status->value,
        ]);
    }

    /**
     * Log a task dispatch event.
     */
    public function logTaskDispatched(AdScriptTask $task): void
    {
        $this->log('task.dispatched', [
            'task_id' => $task->id,
            'status' => $task->status->value,
        ]);
    }

    /**
     * Log a task status change event.
     */
    public function logTaskStatusChange(
        AdScriptTask $task,
        TaskStatus $oldStatus,
        TaskStatus $newStatus
    ): void {
        $this->log('task.status_changed', [
            'task_id' => $task->id,
            'old_status' => $oldStatus->value,
            'new_status' => $newStatus->value,
        ]);
    }

    /**
     * Log a task completion event.
     */
    public function logTaskCompleted(AdScriptTask $task): void
    {
        $this->log('task.completed', [
            'task_id' => $task->id,
            'new_script_length' => strlen($task->new_script ?? ''),
            'analysis_count' => count($task->analysis ?? []),
        ]);
    }

    /**
     * Log a task failure event.
     */
    public function logTaskFailed(AdScriptTask $task, ?string $errorDetails = null): void
    {
        $this->log('task.failed', [
            'task_id' => $task->id,
            'error_details' => $errorDetails ?? $task->error_details,
        ]);
    }

    /**
     * Log an idempotent operation event.
     */
    public function logIdempotentOperation(
        AdScriptTask $task,
        string $operationType,
        bool $wasIdempotent
    ): void {
        $this->log('task.idempotent_operation', [
            'task_id' => $task->id,
            'operation_type' => $operationType,
            'status' => $task->status->value,
            'was_idempotent' => $wasIdempotent,
        ]);
    }

    /**
     * Log a webhook event.
     */
    public function logWebhookEvent(string $direction, AdScriptTask $task, array $payloadInfo): void
    {
        $this->log('webhook.' . $direction, array_merge([
            'task_id' => $task->id,
            'status' => $task->status->value,
        ], $payloadInfo));
    }

    /**
     * Log an API request event.
     */
    public function logApiRequest(string $endpoint, array $context = []): void
    {
        $this->log('api.request', array_merge([
            'endpoint' => $endpoint,
            'method' => request()->method(),
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ], $context));
    }

    /**
     * Log an API response event.
     */
    public function logApiResponse(string $endpoint, int $statusCode, array $context = []): void
    {
        $this->log('api.response', array_merge([
            'endpoint' => $endpoint,
            'status_code' => $statusCode,
        ], $context));
    }

    /**
     * Log a security event.
     */
    public function logSecurityEvent(string $event, array $context = []): void
    {
        $this->log('security.' . $event, $context);
    }

    /**
     * Log an error event.
     */
    public function logError(string $message, \Throwable $exception, array $context = []): void
    {
        $this->log('error', array_merge([
            'message' => $message,
            'exception' => get_class($exception),
            'exception_message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ], $context));
    }

    /**
     * Write a log entry to the audit log channel.
     */
    private function log(string $event, array $context = []): void
    {
        // Add common context data to all audit logs
        $context = array_merge($context, [
            'event' => $event,
            'timestamp' => now()->toISOString(),
            'user_id' => Auth::id(),
            'request_id' => request()->header('X-Request-ID'),
        ]);

        Log::channel(self::CHANNEL)->info($event, $context);
    }
}
