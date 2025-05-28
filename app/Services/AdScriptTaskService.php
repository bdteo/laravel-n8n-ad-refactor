<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\N8nResultPayload;
use App\DTOs\N8nWebhookPayload;
use App\Enums\TaskStatus;
use App\Jobs\TriggerN8nWorkflow;
use App\Models\AdScriptTask;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdScriptTaskService
{
    /**
     * Create a new ad script task.
     */
    public function createTask(array $data): AdScriptTask
    {
        return AdScriptTask::create([
            'reference_script' => $data['reference_script'],
            'outcome_description' => $data['outcome_description'],
        ]);
    }

    /**
     * Create a new ad script task and dispatch it for processing.
     */
    public function createAndDispatchTask(array $data): AdScriptTask
    {
        $task = $this->createTask($data);
        $this->dispatchTask($task);

        return $task;
    }

    /**
     * Dispatch a task for processing via n8n workflow.
     */
    public function dispatchTask(AdScriptTask $task): void
    {
        if (! $this->canProcess($task)) {
            throw new \InvalidArgumentException('Task cannot be processed in its current state');
        }

        TriggerN8nWorkflow::dispatch($task);
    }

    /**
     * Find a task by ID.
     *
     * @throws ModelNotFoundException
     */
    public function findTask(string $id): AdScriptTask
    {
        return AdScriptTask::findOrFail($id);
    }

    /**
     * Update task status to processing (idempotent).
     */
    public function markAsProcessing(AdScriptTask $task): bool
    {
        return $task->markAsProcessing();
    }

    /**
     * Process a successful result from n8n (idempotent).
     */
    public function processSuccessResult(AdScriptTask $task, N8nResultPayload $payload): bool
    {
        if (! $payload->isSuccess() || $payload->newScript === null) {
            Log::warning('Invalid success payload received', [
                'task_id' => $task->id,
                'has_new_script' => $payload->newScript !== null,
                'is_success' => $payload->isSuccess(),
            ]);

            return false;
        }

        $result = $task->markAsCompleted(
            $payload->newScript,
            $payload->analysis ?? []
        );

        if ($result) {
            Log::info('Task marked as completed successfully', [
                'task_id' => $task->id,
                'script_length' => strlen($payload->newScript),
                'analysis_count' => count($payload->analysis ?? []),
            ]);
        } else {
            Log::warning('Failed to mark task as completed', [
                'task_id' => $task->id,
                'current_status' => $task->status->value,
            ]);
        }

        return $result;
    }

    /**
     * Process an error result from n8n (idempotent).
     */
    public function processErrorResult(AdScriptTask $task, N8nResultPayload $payload): bool
    {
        if (! $payload->isError() || $payload->error === null) {
            Log::warning('Invalid error payload received', [
                'task_id' => $task->id,
                'has_error' => $payload->error !== null,
                'is_error' => $payload->isError(),
            ]);

            return false;
        }

        $result = $task->markAsFailed($payload->error);

        if ($result) {
            Log::info('Task marked as failed successfully', [
                'task_id' => $task->id,
                'error' => $payload->error,
            ]);
        } else {
            Log::warning('Failed to mark task as failed', [
                'task_id' => $task->id,
                'current_status' => $task->status->value,
                'error' => $payload->error,
            ]);
        }

        return $result;
    }

    /**
     * Process any result from n8n (success or error) with idempotency.
     */
    public function processResult(AdScriptTask $task, N8nResultPayload $payload): bool
    {
        // Log the processing attempt
        Log::info('Processing result payload', [
            'task_id' => $task->id,
            'current_status' => $task->status->value,
            'payload_type' => $payload->isSuccess() ? 'success' : ($payload->isError() ? 'error' : 'unknown'),
        ]);

        // Check if task is already in a final state
        if ($task->isFinal()) {
            Log::info('Task already in final state, checking for idempotent operation', [
                'task_id' => $task->id,
                'status' => $task->status->value,
            ]);

            // For idempotency, check if the result matches the current state
            if ($payload->isSuccess() && $task->status === TaskStatus::COMPLETED) {
                $isIdempotent = $task->new_script === $payload->newScript &&
                               $task->analysis === ($payload->analysis ?? []);

                Log::info('Checking success result idempotency', [
                    'task_id' => $task->id,
                    'is_idempotent' => $isIdempotent,
                ]);

                return $isIdempotent;
            }

            if ($payload->isError() && $task->status === TaskStatus::FAILED) {
                $isIdempotent = $task->error_details === $payload->error;

                Log::info('Checking error result idempotency', [
                    'task_id' => $task->id,
                    'is_idempotent' => $isIdempotent,
                ]);

                return $isIdempotent;
            }

            // Different result type than current state - not idempotent
            Log::warning('Non-idempotent operation attempted on final task', [
                'task_id' => $task->id,
                'current_status' => $task->status->value,
                'payload_type' => $payload->isSuccess() ? 'success' : 'error',
            ]);

            return false;
        }

        // Process the result based on type
        if ($payload->isSuccess()) {
            return $this->processSuccessResult($task, $payload);
        }

        if ($payload->isError()) {
            return $this->processErrorResult($task, $payload);
        }

        // Invalid payload - mark as failed
        Log::error('Invalid result payload received', [
            'task_id' => $task->id,
            'payload' => $payload->toArray(),
        ]);

        return $task->markAsFailed('Invalid result payload received from n8n');
    }

    /**
     * Process result with enhanced idempotency and error handling.
     * This method wraps processResult with additional safety measures.
     */
    public function processResultIdempotent(AdScriptTask $task, N8nResultPayload $payload): array
    {
        try {
            return DB::transaction(function () use ($task, $payload) {
                // Refresh task to get latest state
                $task->refresh();

                $success = $this->processResult($task, $payload);

                // Refresh again to get updated state
                $task->refresh();

                return [
                    'success' => $success,
                    'task_id' => $task->id,
                    'status' => $task->status->value,
                    'was_updated' => $success,
                    'message' => $success ? 'Result processed successfully' : 'Result processing failed or was idempotent',
                ];
            });
        } catch (\Exception $e) {
            Log::error('Exception during result processing', [
                'task_id' => $task->id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'task_id' => $task->id,
                'status' => $task->status->value,
                'was_updated' => false,
                'message' => 'Exception occurred during processing',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create a webhook payload for sending to n8n.
     */
    public function createWebhookPayload(AdScriptTask $task): N8nWebhookPayload
    {
        return N8nWebhookPayload::fromAdScriptTask($task);
    }

    /**
     * Check if a task can be processed.
     */
    public function canProcess(AdScriptTask $task): bool
    {
        return $task->canProcess();
    }

    /**
     * Check if a task is in a final state.
     */
    public function isFinal(AdScriptTask $task): bool
    {
        return $task->isFinal();
    }

    /**
     * Get task status.
     */
    public function getStatus(AdScriptTask $task): TaskStatus
    {
        return $task->status;
    }

    /**
     * Update task with error details (idempotent).
     */
    public function markAsFailed(AdScriptTask $task, string $errorDetails): bool
    {
        return $task->markAsFailed($errorDetails);
    }
}
