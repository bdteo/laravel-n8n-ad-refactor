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
        // Log API request in audit log
        $this->auditLogService->logApiRequest('store_ad_script_task', [
            'client_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'reference_script_length' => strlen($request->input('reference_script')),
            'outcome_description_length' => strlen($request->input('outcome_description')),
        ]);
        
        try {
            $task = $this->adScriptTaskService->createAndDispatchTask($request->validated());

            $response = $this->acceptedResponse([
                'id' => $task->id,
                'status' => $task->status->value,
                'created_at' => $task->created_at?->toISOString(),
            ], 'Ad script task created and queued for processing');
            
            // Log API response in audit log
            $this->auditLogService->logApiResponse('store_ad_script_task', $response->getStatusCode(), [
                'task_id' => $task->id,
                'status' => $task->status->value,
            ]);
            
            return $response;

        } catch (AdScriptTaskException $e) {
            $this->auditLogService->logError('Creating and dispatching ad script task failed', $e, [
                'reference_script_length' => strlen($request->input('reference_script', '')),
                'outcome_description_length' => strlen($request->input('outcome_description', '')),
            ]);
            return $this->handleException($e, 'creating and dispatching ad script task');
        } catch (Throwable $e) {
            $this->auditLogService->logError('Creating ad script task failed', $e, [
                'reference_script_length' => strlen($request->input('reference_script', '')),
                'outcome_description_length' => strlen($request->input('outcome_description', '')),
            ]);
            return $this->handleException($e, 'creating ad script task');
        }
    }
}
