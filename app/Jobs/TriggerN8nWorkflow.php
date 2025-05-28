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
            Log::info('Starting n8n workflow trigger', [
                'task_id' => $this->task->id,
                'attempt' => $this->attempts(),
                'webhook_url' => $n8nClient->getWebhookUrl(),
            ]);

            // Check if task can still be processed
            if (! $adScriptTaskService->canProcess($this->task)) {
                Log::warning('Task cannot be processed, skipping', [
                    'task_id' => $this->task->id,
                    'status' => $this->task->status->value,
                ]);

                return;
            }

            // Mark task as processing
            if (! $adScriptTaskService->markAsProcessing($this->task)) {
                Log::error('Failed to mark task as processing', [
                    'task_id' => $this->task->id,
                ]);

                throw new Exception('Failed to mark task as processing');
            }

            // Create webhook payload
            $payload = $adScriptTaskService->createWebhookPayload($this->task);

            // Trigger the workflow using the n8n client
            $response = $n8nClient->triggerWorkflow($payload);

            Log::info('Successfully triggered n8n workflow', [
                'task_id' => $this->task->id,
                'response' => $response,
            ]);

        } catch (N8nClientException $exception) {
            Log::error('N8n client error while triggering workflow', [
                'task_id' => $this->task->id,
                'attempt' => $this->attempts(),
                'error' => $exception->getMessage(),
            ]);

            // If this is the final attempt, mark task as failed
            if ($this->attempts() >= $this->tries) {
                $adScriptTaskService->markAsFailed(
                    $this->task,
                    "Failed to trigger n8n workflow after {$this->tries} attempts: {$exception->getMessage()}"
                );
            }

            throw $exception;

        } catch (Exception $exception) {
            Log::error('Unexpected error while triggering n8n workflow', [
                'task_id' => $this->task->id,
                'attempt' => $this->attempts(),
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            // If this is the final attempt, mark task as failed
            if ($this->attempts() >= $this->tries) {
                $adScriptTaskService->markAsFailed(
                    $this->task,
                    "Failed to trigger n8n workflow after {$this->tries} attempts: {$exception->getMessage()}"
                );
            }

            throw $exception;
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
