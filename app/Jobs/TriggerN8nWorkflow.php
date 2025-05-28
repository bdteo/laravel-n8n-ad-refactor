<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\N8nClientInterface;
use App\Exceptions\N8nClientException;
use App\Models\AdScriptTask;
use App\Services\AdScriptTaskService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TriggerN8nWorkflow implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public array $backoff = [10, 30, 60];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public AdScriptTask $task
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(
        AdScriptTaskService $adScriptTaskService,
        N8nClientInterface $n8nClient
    ): void {
        try {
            $this->logJobStart($n8nClient);
            $this->ensureTaskCanBeProcessed($adScriptTaskService);
            $this->markTaskAsProcessing($adScriptTaskService);
            $this->triggerN8nAndLog($adScriptTaskService, $n8nClient);
        } catch (N8nClientException|Exception $e) {
            $this->handleWorkflowTriggerFailure($adScriptTaskService, $e);

            throw $e;
        }
    }

    /**
     * Log the job start information.
     */
    private function logJobStart(N8nClientInterface $n8nClient): void
    {
        Log::info('Starting n8n workflow trigger', [
            'task_id' => $this->task->id,
            'attempt' => $this->attempts(),
            'webhook_url' => $n8nClient->getWebhookUrl(),
        ]);
    }

    /**
     * Ensure the task can be processed.
     */
    private function ensureTaskCanBeProcessed(AdScriptTaskService $service): void
    {
        if (! $service->canProcess($this->task)) {
            Log::warning('Task cannot be processed, skipping', [
                'task_id' => $this->task->id,
                'status' => $this->task->status->value,
            ]);

            throw new Exception('Task cannot be processed in its current state');
        }
    }

    /**
     * Mark the task as processing.
     */
    private function markTaskAsProcessing(AdScriptTaskService $service): void
    {
        if (! $service->markAsProcessing($this->task)) {
            Log::error('Failed to mark task as processing', [
                'task_id' => $this->task->id,
            ]);

            throw new Exception('Failed to mark task as processing');
        }
    }

    /**
     * Trigger the n8n workflow and log the result.
     */
    private function triggerN8nAndLog(AdScriptTaskService $service, N8nClientInterface $client): void
    {
        $payload = $service->createWebhookPayload($this->task);
        $response = $client->triggerWorkflow($payload);

        Log::info('Successfully triggered n8n workflow', [
            'task_id' => $this->task->id,
            'response' => $response,
        ]);
    }

    /**
     * Handle workflow trigger failure.
     */
    private function handleWorkflowTriggerFailure(AdScriptTaskService $service, Exception $exception): void
    {
        $isN8nClientError = $exception instanceof N8nClientException;
        $logMessage = $isN8nClientError
            ? 'N8n client error while triggering workflow'
            : 'Unexpected error while triggering workflow';

        Log::error($logMessage, [
            'task_id' => $this->task->id,
            'attempt' => $this->attempts(),
            'error' => $exception->getMessage(),
            'trace' => $isN8nClientError ? null : $exception->getTraceAsString(),
        ]);

        if ($this->attempts() >= $this->tries) {
            $service->markAsFailed(
                $this->task,
                "Failed to trigger n8n workflow after {$this->tries} attempts: {$exception->getMessage()}"
            );
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Exception $exception): void
    {
        Log::error('TriggerN8nWorkflow job failed permanently', [
            'task_id' => $this->task->id,
            'error' => $exception->getMessage(),
        ]);

        // Ensure task is marked as failed
        $adScriptTaskService = app(AdScriptTaskService::class);
        $adScriptTaskService->markAsFailed(
            $this->task,
            "Job failed permanently: {$exception->getMessage()}"
        );
    }
}
