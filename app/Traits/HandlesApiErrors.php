<?php

declare(strict_types=1);

namespace App\Traits;

use App\Exceptions\AdScriptTaskException;
use App\Exceptions\BusinessValidationException;
use App\Exceptions\ExternalServiceException;
use App\Exceptions\N8nClientException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Trait for handling API errors consistently across controllers.
 */
trait HandlesApiErrors
{
    /**
     * Handle exceptions and return appropriate JSON responses.
     */
    protected function handleException(Throwable $exception, string $context = ''): JsonResponse
    {
        // Log the exception with context
        $this->logException($exception, $context);

        // Return appropriate response based on exception type
        return match (true) {
            $exception instanceof BusinessValidationException => $this->validationErrorResponse($exception),
            $exception instanceof ModelNotFoundException => $this->notFoundResponse('Resource not found'),
            $exception instanceof AdScriptTaskException => $this->taskErrorResponse($exception),
            $exception instanceof N8nClientException,
            $exception instanceof ExternalServiceException => $this->serviceErrorResponse($exception),
            default => $this->serverErrorResponse($exception),
        };
    }

    /**
     * Return a validation error response.
     */
    protected function validationErrorResponse(BusinessValidationException $exception): JsonResponse
    {
        return response()->json([
            'error' => true,
            'message' => $exception->getMessage(),
            'type' => 'validation_error',
            'errors' => $exception->getErrors(),
            'timestamp' => now()->toISOString(),
        ], 422);
    }

    /**
     * Return a not found error response.
     */
    protected function notFoundResponse(string $message = 'Resource not found'): JsonResponse
    {
        return response()->json([
            'error' => true,
            'message' => $message,
            'type' => 'not_found',
            'timestamp' => now()->toISOString(),
        ], 404);
    }

    /**
     * Return a task-specific error response.
     */
    protected function taskErrorResponse(AdScriptTaskException $exception): JsonResponse
    {
        $statusCode = str_contains($exception->getMessage(), 'not found') ? 404 : 422;

        return response()->json([
            'error' => true,
            'message' => $this->sanitizeErrorMessage($exception->getMessage()),
            'type' => 'task_error',
            'timestamp' => now()->toISOString(),
        ], $statusCode);
    }

    /**
     * Return a service error response.
     */
    protected function serviceErrorResponse(Throwable $exception): JsonResponse
    {
        $serviceName = 'external service';
        $retryAfter = 30;

        if ($exception instanceof ExternalServiceException) {
            $serviceName = $exception->getServiceName();
            $retryAfter = $this->getRetryAfterFromException($exception);
        } elseif ($exception instanceof N8nClientException) {
            $serviceName = 'n8n';
        }

        return response()->json([
            'error' => true,
            'message' => "Service '{$serviceName}' is temporarily unavailable. Please try again later.",
            'type' => 'service_error',
            'service' => $serviceName,
            'retry_after' => $retryAfter,
            'timestamp' => now()->toISOString(),
        ], 503);
    }

    /**
     * Return a server error response.
     */
    protected function serverErrorResponse(Throwable $exception): JsonResponse
    {
        $message = app()->environment('production')
            ? 'An unexpected error occurred. Please try again later.'
            : $exception->getMessage();

        return response()->json([
            'error' => true,
            'message' => $message,
            'type' => 'server_error',
            'timestamp' => now()->toISOString(),
        ], 500);
    }

    /**
     * Return a success response with data.
     */
    protected function successResponse(array $data, string $message = 'Success', int $statusCode = 200): JsonResponse
    {
        return response()->json([
            'error' => false,
            'message' => $message,
            'data' => $data,
            'timestamp' => now()->toISOString(),
        ], $statusCode);
    }

    /**
     * Return a created response.
     */
    protected function createdResponse(array $data, string $message = 'Resource created successfully'): JsonResponse
    {
        return $this->successResponse($data, $message, 201);
    }

    /**
     * Return an accepted response (for async operations).
     */
    protected function acceptedResponse(array $data, string $message = 'Request accepted for processing'): JsonResponse
    {
        return $this->successResponse($data, $message, 202);
    }

    /**
     * Log exception with controller context.
     */
    private function logException(Throwable $exception, string $context): void
    {
        $logContext = [
            'controller' => static::class,
            'context' => $context,
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];

        // Add request information if available
        if (request()) {
            $logContext['request'] = [
                'method' => request()->method(),
                'url' => request()->fullUrl(),
                'ip' => request()->ip(),
            ];
        }

        Log::error('Controller exception occurred', $logContext);
    }

    /**
     * Sanitize error messages for client consumption.
     */
    private function sanitizeErrorMessage(string $message): string
    {
        // Remove UUIDs and sensitive information
        $message = preg_replace('/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}/i', '[ID]', $message) ?? $message;
        $message = preg_replace('/\b[a-f0-9]{32,}\b/', '[HASH]', $message) ?? $message;

        return $message;
    }

    /**
     * Get retry-after value from external service exception.
     */
    private function getRetryAfterFromException(ExternalServiceException $exception): int
    {
        if ($exception->getHttpStatusCode() === 429) {
            return 60; // Rate limited
        }

        if (str_contains($exception->getMessage(), 'timeout')) {
            return 30; // Timeout
        }

        return 30; // Default
    }
}
