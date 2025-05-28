<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
        'webhook_secret',
        'api_key',
        'token',
        'secret',
    ];

    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        N8nClientException::class => 'warning',
        ExternalServiceException::class => 'warning',
        BusinessValidationException::class => 'info',
        AdScriptTaskException::class => 'warning',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            $this->logExceptionWithContext($e);
        });

        $this->renderable(function (Throwable $e, Request $request) {
            if ($request->expectsJson()) {
                return $this->renderApiException($e, $request);
            }
        });
    }

    /**
     * Log exception with enhanced context information.
     */
    private function logExceptionWithContext(Throwable $exception): void
    {
        $context = [
            'exception_class' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $this->getFilteredTrace($exception),
        ];

        // Add request context if available
        if (request()) {
            $context['request'] = [
                'url' => request()->fullUrl(),
                'method' => request()->method(),
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'headers' => $this->getFilteredHeaders(request()),
            ];

            // Add user context if authenticated
            if (auth()->check()) {
                $context['user'] = [
                    'id' => auth()->id(),
                    'email' => auth()->user()->email ?? 'unknown',
                ];
            }
        }

        // Add specific context for custom exceptions
        $context = array_merge($context, $this->getExceptionSpecificContext($exception));

        // Use appropriate log channel based on exception type
        $channel = $this->getLogChannelForException($exception);
        Log::channel($channel)->error('Exception occurred', $context);
    }

    /**
     * Render API exception responses.
     */
    private function renderApiException(Throwable $exception, Request $request): JsonResponse
    {
        $statusCode = $this->getStatusCode($exception);
        $errorData = $this->getErrorData($exception);

        // Add request ID for tracking
        $errorData['request_id'] = $request->header('X-Request-ID', uniqid('req_', true));
        $errorData['timestamp'] = now()->toISOString();

        return response()->json($errorData, $statusCode);
    }

    /**
     * Get HTTP status code for exception.
     */
    private function getStatusCode(Throwable $exception): int
    {
        return match (true) {
            $exception instanceof ValidationException => 422,
            $exception instanceof BusinessValidationException => 422,
            $exception instanceof ModelNotFoundException,
            $exception instanceof NotFoundHttpException,
            $exception instanceof AdScriptTaskException && str_contains($exception->getMessage(), 'not found') => 404,
            $exception instanceof AuthenticationException => 401,
            $exception instanceof HttpException => $exception->getStatusCode(),
            $exception instanceof N8nClientException,
            $exception instanceof ExternalServiceException => 503,
            default => 500,
        };
    }

    /**
     * Get error data for API response.
     */
    private function getErrorData(Throwable $exception): array
    {
        $baseData = [
            'error' => true,
            'message' => $this->getClientSafeMessage($exception),
            'type' => $this->getErrorType($exception),
        ];

        // Add specific data for different exception types
        return match (true) {
            $exception instanceof ValidationException => array_merge($baseData, [
                'errors' => $exception->errors(),
            ]),
            $exception instanceof BusinessValidationException => array_merge($baseData, [
                'errors' => $exception->getErrors(),
            ]),
            $exception instanceof ExternalServiceException => array_merge($baseData, [
                'service' => $exception->getServiceName(),
                'retry_after' => $this->getRetryAfter($exception),
            ]),
            $exception instanceof N8nClientException => array_merge($baseData, [
                'service' => 'n8n',
                'retry_after' => 30, // Default retry after 30 seconds
            ]),
            default => $baseData,
        };
    }

    /**
     * Get client-safe error message.
     */
    private function getClientSafeMessage(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof ValidationException => 'The given data was invalid.',
            $exception instanceof BusinessValidationException => $exception->getMessage(),
            $exception instanceof ModelNotFoundException => 'The requested resource was not found.',
            $exception instanceof NotFoundHttpException => 'The requested endpoint was not found.',
            $exception instanceof AuthenticationException => 'Authentication required.',
            $exception instanceof AdScriptTaskException => $this->sanitizeTaskExceptionMessage($exception),
            $exception instanceof N8nClientException => 'External service temporarily unavailable. Please try again later.',
            $exception instanceof ExternalServiceException => "Service '{$exception->getServiceName()}' is temporarily unavailable.",
            app()->environment('production') => 'An unexpected error occurred. Please try again later.',
            default => $exception->getMessage(),
        };
    }

    /**
     * Get error type for categorization.
     */
    private function getErrorType(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof ValidationException,
            $exception instanceof BusinessValidationException => 'validation_error',
            $exception instanceof ModelNotFoundException,
            $exception instanceof NotFoundHttpException => 'not_found',
            $exception instanceof AuthenticationException => 'authentication_error',
            $exception instanceof AdScriptTaskException => 'task_error',
            $exception instanceof N8nClientException,
            $exception instanceof ExternalServiceException => 'service_error',
            default => 'server_error',
        };
    }

    /**
     * Get exception-specific context for logging.
     */
    private function getExceptionSpecificContext(Throwable $exception): array
    {
        return match (true) {
            $exception instanceof ExternalServiceException => [
                'service_name' => $exception->getServiceName(),
                'service_url' => $exception->getServiceUrl(),
                'http_status_code' => $exception->getHttpStatusCode(),
                'service_response' => $this->truncateString($exception->getServiceResponse(), 1000),
            ],
            $exception instanceof BusinessValidationException => [
                'validation_errors' => $exception->getErrors(),
            ],
            $exception instanceof ValidationException => [
                'validation_errors' => $exception->errors(),
            ],
            default => [],
        };
    }

    /**
     * Get appropriate log channel for exception type.
     */
    private function getLogChannelForException(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof N8nClientException,
            $exception instanceof ExternalServiceException => 'external_services',
            $exception instanceof AdScriptTaskException => 'tasks',
            $exception instanceof BusinessValidationException,
            $exception instanceof ValidationException => 'validation',
            $exception instanceof AuthenticationException => 'security',
            default => 'stack',
        };
    }

    /**
     * Get filtered request headers (remove sensitive data).
     */
    private function getFilteredHeaders(Request $request): array
    {
        $headers = $request->headers->all();
        $sensitiveHeaders = [
            'authorization',
            'x-api-key',
            'x-webhook-secret',
            'x-n8n-signature',
            'cookie',
        ];

        foreach ($sensitiveHeaders as $header) {
            if (isset($headers[$header])) {
                $headers[$header] = ['[FILTERED]'];
            }
        }

        return $headers;
    }

    /**
     * Get filtered stack trace (remove sensitive file paths).
     */
    private function getFilteredTrace(Throwable $exception): array
    {
        $trace = $exception->getTrace();
        $basePath = base_path();

        return array_map(function ($frame) use ($basePath) {
            if (isset($frame['file'])) {
                $frame['file'] = str_replace($basePath, '', $frame['file']);
            }
            // Remove sensitive arguments
            if (isset($frame['args'])) {
                unset($frame['args']);
            }

            return $frame;
        }, array_slice($trace, 0, 10)); // Limit to first 10 frames
    }

    /**
     * Sanitize task exception messages for client consumption.
     */
    private function sanitizeTaskExceptionMessage(AdScriptTaskException $exception): string
    {
        $message = $exception->getMessage();

        // Remove internal IDs and sensitive information
        $message = preg_replace('/task [a-f0-9-]{36}/i', 'task [ID]', $message) ?? $message;
        $message = preg_replace('/\b[a-f0-9]{32,}\b/', '[HASH]', $message) ?? $message;

        return $message;
    }

    /**
     * Get retry-after value for service exceptions.
     */
    private function getRetryAfter(ExternalServiceException $exception): ?int
    {
        if ($exception->getHttpStatusCode() === 429) {
            return 60; // Rate limited, retry after 1 minute
        }

        if (str_contains($exception->getMessage(), 'timeout')) {
            return 30; // Timeout, retry after 30 seconds
        }

        return null;
    }

    /**
     * Truncate string to specified length.
     */
    private function truncateString(?string $string, int $length): ?string
    {
        if ($string === null || strlen($string) <= $length) {
            return $string;
        }

        return substr($string, 0, $length) . '...';
    }
}
