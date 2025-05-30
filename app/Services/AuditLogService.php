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
        // Create a safe base context
        $safeContext = [
            'endpoint' => $endpoint,
        ];

        // Only add request details if we have a request
        if (app()->runningInConsole() === false && request() !== null) {
            $safeContext['method'] = request()->method();
            $safeContext['ip'] = request()->ip();
            $safeContext['user_agent'] = request()->userAgent();
        }

        // Filter and merge additional context
        $filteredContext = $this->filterNonScalarValues($context);

        $this->log('api.request', array_merge($safeContext, $filteredContext));
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
        // Create a safe context array that won't cause serialization issues
        $safeContext = [
            'message' => $message,
            'exception' => get_class($exception),
            'exception_message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'code' => $exception->getCode(),
            'trace' => $this->getSafeTrace($exception->getTrace()),
        ];

        // Add any additional context, filtering out any non-scalar values
        foreach ($context as $key => $value) {
            if (is_scalar($value) || is_null($value)) {
                $safeContext[$key] = $value;
            } elseif (is_array($value)) {
                $safeContext[$key] = $this->filterNonScalarValues($value);
            } else {
                $safeContext[$key] = is_object($value) ? get_class($value) : gettype($value);
            }
        }

        $this->log('error', $safeContext);
    }

    /**
     * Filter out non-scalar values from an array.
     */
    private function filterNonScalarValues(array $array): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            if (is_scalar($value) || is_null($value)) {
                $result[$key] = $value;
            } elseif (is_array($value)) {
                $result[$key] = $this->filterNonScalarValues($value);
            } else {
                $result[$key] = is_object($value) ? get_class($value) : gettype($value);
            }
        }

        return $result;
    }

    /**
     * Get a safe representation of a stack trace.
     */
    private function getSafeTrace(array $trace): array
    {
        $safeTrace = [];
        foreach ($trace as $i => $frame) {
            $safeFrame = [];
            if (isset($frame['file'])) {
                $safeFrame['file'] = $frame['file'];
            }
            if (isset($frame['line'])) {
                $safeFrame['line'] = $frame['line'];
            }
            if (isset($frame['function'])) {
                $safeFrame['function'] = $frame['function'];
            }
            if (isset($frame['class'])) {
                $safeFrame['class'] = $frame['class'];
            }
            if (isset($frame['type'])) {
                $safeFrame['type'] = $frame['type'];
            }
            $safeTrace[] = $safeFrame;
        }

        return $safeTrace;
    }

    /**
     * Write a log entry to the audit log channel.
     */
    private function log(string $event, array $context = []): void
    {
        try {
            // Ensure all context values are safe for serialization
            $safeContext = $this->filterNonScalarValues($context);

            // Add common context data to all audit logs
            $commonContext = [
                'event' => $event,
                'timestamp' => now()->toISOString(),
            ];

            // Only add request-specific data if we have a request
            if (app()->runningInConsole() === false && request() !== null) {
                $commonContext['user_id'] = Auth::id();
                $commonContext['request_id'] = request()->header('X-Request-ID');
            }

            $fullContext = array_merge($safeContext, $commonContext);

            // Skip actual logging in test environment to avoid channel configuration issues
            if (app()->environment('testing')) {
                return; // Silent in tests
            }

            // Use the configured audit log channel in non-test environments
            Log::channel(self::CHANNEL)->info($event, $fullContext);
        } catch (\Throwable $e) {
            // If logging fails, try to log the error without the problematic context
            if (! app()->environment('testing')) {
                Log::channel(self::CHANNEL)->error('Failed to log audit event: ' . $e->getMessage(), [
                    'original_event' => $event,
                    'error' => get_class($e),
                    'error_message' => $e->getMessage(),
                ]);
            }
        }
    }
}
