<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\AdScriptTaskException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdScriptTaskRequest;
use App\Services\AdScriptTaskService;
use App\Traits\HandlesApiErrors;
use Illuminate\Http\JsonResponse;
use Throwable;

class StoreAdScriptTaskController extends Controller
{
    use HandlesApiErrors;

    public function __construct(
        private readonly AdScriptTaskService $adScriptTaskService
    ) {
    }

    /**
     * Handle the incoming request.
     */
    public function __invoke(StoreAdScriptTaskRequest $request): JsonResponse
    {
        try {
            $task = $this->adScriptTaskService->createAndDispatchTask($request->validated());

            return $this->acceptedResponse([
                'id' => $task->id,
                'status' => $task->status->value,
                'created_at' => $task->created_at?->toISOString(),
            ], 'Ad script task created and queued for processing');

        } catch (AdScriptTaskException $e) {
            return $this->handleException($e, 'creating and dispatching ad script task');
        } catch (Throwable $e) {
            return $this->handleException($e, 'creating ad script task');
        }
    }
}
