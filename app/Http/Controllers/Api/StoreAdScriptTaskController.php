<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\AdScriptTaskException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdScriptTaskRequest;
use App\Services\AdScriptTaskService;
use App\Services\AuditLogService;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;
use Throwable;

class StoreAdScriptTaskController extends Controller
{
    use HandlesApiErrors;

    public function __construct(
        private readonly AdScriptTaskService $adScriptTaskService,
        private readonly AuditLogService $auditLogService
    ) {
    }

    /**
     * Handle the incoming request.
     */
    public function __invoke(StoreAdScriptTaskRequest $request): JsonResponse
    {
        $this->logApiRequest($request);

        try {
            // Use logApiRequest to log the start of task creation
            $this->auditLogService->logApiRequest('store_ad_script_task', [
                'environment' => App::environment(),
                'testing' => App::environment('testing'),
                'input_keys' => array_keys($request->validated()),
            ]);

            // In testing environments, always use createAndDispatchTask to ensure job is properly queued
            if (App::environment('testing')) {
                $task = $this->adScriptTaskService->createAndDispatchTask($request->validated());
            } else {
                // Create task and dispatch it to n8n in production mode
                $task = $this->adScriptTaskService->createAndDispatchTask($request->validated());
            }

            $response = $this->buildSuccessResponse($task);
            $this->logApiResponse($task, $response);

            return $response;
        } catch (AdScriptTaskException $e) {
            // Use logError for exceptions
            $this->auditLogService->logError(
                'Task creation failed with AdScriptTaskException',
                $e,
                [
                    'error_type' => 'AdScriptTaskException',
                    'code' => $e->getCode(),
                ]
            );

            return $this->handleTaskException($request, $e, 'Creating and dispatching ad script task failed');
        } catch (\Throwable $e) {
            // Use logError for all other exceptions
            $this->auditLogService->logError(
                'Unexpected error during task creation',
                $e,
                [
                    'error_type' => 'UnexpectedError',
                    'code' => $e->getCode(),
                ]
            );

            return $this->handleTaskException($request, $e, 'Creating ad script task failed');
        }
    }

    /**
     * Log the incoming API request.
     */
    private function logApiRequest(StoreAdScriptTaskRequest $request): void
    {
        $this->auditLogService->logApiRequest('store_ad_script_task', [
            'client_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'reference_script_length' => is_string($request->input('reference_script')) ? strlen($request->input('reference_script')) : 0,
            'outcome_description_length' => is_string($request->input('outcome_description')) ? strlen($request->input('outcome_description')) : 0,
        ]);
    }

    /**
     * Build a success response for the created task.
     *
     * @param \App\Models\AdScriptTask $task The created task
     */
    private function buildSuccessResponse(\App\Models\AdScriptTask $task): JsonResponse
    {
        return $this->acceptedResponse([
            'id' => $task->id,
            'status' => $task->status->value,
            'created_at' => $task->created_at?->toISOString(),
        ], 'Ad script task created and queued for processing');
    }

    /**
     * Log the API response.
     *
     * @param \App\Models\AdScriptTask $task The created task
     */
    private function logApiResponse(\App\Models\AdScriptTask $task, JsonResponse $response): void
    {
        $this->auditLogService->logApiResponse('store_ad_script_task', $response->getStatusCode(), [
            'task_id' => $task->id,
            'status' => $task->status->value,
        ]);
    }

    /**
     * Handle exceptions for task creation.
     */
    private function handleTaskException(StoreAdScriptTaskRequest $request, Throwable $e, string $message): JsonResponse
    {
        $this->auditLogService->logError($message, $e, [
            'reference_script_length' => is_string($request->input('reference_script', '')) ? strlen($request->input('reference_script', '')) : 0,
            'outcome_description_length' => is_string($request->input('outcome_description', '')) ? strlen($request->input('outcome_description', '')) : 0,
        ]);

        $contextMessage = $e instanceof AdScriptTaskException
            ? 'creating and dispatching ad script task'
            : 'creating ad script task';

        return $this->handleException($e, $contextMessage);
    }
}
