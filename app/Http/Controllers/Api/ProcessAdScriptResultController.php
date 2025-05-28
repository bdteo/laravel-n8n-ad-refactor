<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DTOs\N8nResultPayload;
use App\Exceptions\AdScriptTaskException;
use App\Exceptions\BusinessValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProcessAdScriptResultRequest;
use App\Models\AdScriptTask;
use App\Services\AdScriptTaskService;
use App\Services\AuditLogService;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessAdScriptResultController extends Controller
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
    public function __invoke(ProcessAdScriptResultRequest $request, AdScriptTask $task): JsonResponse
    {
        $this->logApiRequest($request, $task);

        try {
            $this->logProcessingStart($request, $task);
            $resultPayload = $this->createResultPayload($request);
            $result = $this->processResult($task, $resultPayload);
            $this->logProcessingCompletion($task, $result);
            $response = $this->buildSuccessResponse($task, $result);
            $this->logApiResponse($task, $result, $response);

            return $response;
        } catch (Throwable $e) {
            return $this->handleProcessingException($task, $e);
        }
    }

    /**
     * Log the incoming API request.
     */
    private function logApiRequest(ProcessAdScriptResultRequest $request, AdScriptTask $task): void
    {
        $this->auditLogService->logApiRequest('process_ad_script_result', [
            'task_id' => $task->id,
            'task_status' => $task->status->value,
            'has_new_script' => ! empty($request->validated()['new_script']),
            'has_error' => ! empty($request->validated()['error']),
            'client_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    /**
     * Log the start of processing.
     */
    private function logProcessingStart(ProcessAdScriptResultRequest $request, AdScriptTask $task): void
    {
        Log::info('Processing ad script result', [
            'task_id' => $task->id,
            'task_status' => $task->status->value,
            'has_new_script' => ! empty($request->validated()['new_script']),
            'has_error' => ! empty($request->validated()['error']),
            'request_timestamp' => now()->toISOString(),
        ]);
    }

    /**
     * Create result payload from request.
     */
    private function createResultPayload(ProcessAdScriptResultRequest $request): N8nResultPayload
    {
        return N8nResultPayload::fromArray($request->validated());
    }

    /**
     * Process the result with enhanced idempotency.
     */
    private function processResult(AdScriptTask $task, N8nResultPayload $resultPayload): array
    {
        return $this->adScriptTaskService->processResultIdempotent($task, $resultPayload);
    }

    /**
     * Log the completion of processing.
     */
    private function logProcessingCompletion(AdScriptTask $task, array $result): void
    {
        Log::info('Ad script result processing completed', [
            'task_id' => $task->id,
            'success' => $result['success'],
            'was_updated' => $result['was_updated'],
            'final_status' => $result['status'],
            'message' => $result['message'],
        ]);
    }

    /**
     * Log the API response.
     */
    private function logApiResponse(AdScriptTask $task, array $result, JsonResponse $response): void
    {
        $this->auditLogService->logApiResponse('process_ad_script_result', $response->getStatusCode(), [
            'task_id' => $task->id,
            'success' => $result['success'],
            'was_updated' => $result['was_updated'],
            'final_status' => $result['status'],
        ]);
    }

    /**
     * Handle processing exceptions.
     */
    private function handleProcessingException(AdScriptTask $task, Throwable $e): JsonResponse
    {
        $errorContext = $this->getErrorContext($e);

        $this->auditLogService->logError("Processing ad script result - {$errorContext}", $e, [
            'task_id' => $task->id,
            'task_status' => $task->status->value,
        ]);

        return $this->handleException($e, "processing ad script result - {$errorContext}");
    }

    /**
     * Get error context based on exception type.
     */
    private function getErrorContext(Throwable $e): string
    {
        if ($e instanceof BusinessValidationException) {
            return 'validation error';
        }

        if ($e instanceof AdScriptTaskException) {
            return 'task error';
        }

        return 'unexpected error';
    }

    /**
     * Build a success response based on processing result.
     */
    private function buildSuccessResponse(AdScriptTask $task, array $result): JsonResponse
    {
        // Refresh task to ensure we have the latest data
        $task->refresh();

        $data = [
            'id' => $task->id,
            'status' => $task->status->value,
            'updated_at' => $task->updated_at?->toISOString(),
            'was_updated' => $result['was_updated'] ?? false,
        ];

        // Include result data for completed tasks
        if ($task->status->value === 'completed') {
            $data['new_script'] = $task->new_script;
            $data['analysis'] = $task->analysis;
        }

        // Include error details for failed tasks
        if ($task->status->value === 'failed') {
            $data['error_details'] = $task->error_details;
        }

        // Determine status code based on result
        $statusCode = $this->determineHttpStatus($result);

        return $this->successResponse($data, $result['message'], $statusCode);
    }

    /**
     * Determine the appropriate HTTP status code based on processing result.
     */
    private function determineHttpStatus(array $result): int
    {
        // If processing failed due to an exception
        if (isset($result['error'])) {
            return 500;
        }

        // If processing succeeded or was idempotent
        if ($result['success']) {
            return 200;
        }

        // If processing failed due to validation or state issues
        return 422;
    }
}
