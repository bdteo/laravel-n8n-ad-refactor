<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\AuditLogService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditLogMiddleware
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(
        private readonly AuditLogService $auditLogService
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Track request start time
        $startTime = microtime(true);

        // Skip logging for specific paths (like health checks)
        if ($this->shouldSkipPath($request->path())) {
            return $next($request);
        }

        // Get basic request info
        $endpoint = $request->path();
        $method = $request->method();

        // Create context with relevant request info
        $context = [
            'method' => $method,
            'endpoint' => $endpoint,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'content_type' => $request->header('Content-Type'),
            'accept' => $request->header('Accept'),
            'has_auth' => (bool) $request->header('Authorization'),
            'is_json' => $request->isJson(),
            'is_xhr' => $request->ajax(),
        ];

        // Add request ID if present
        if ($requestId = $request->header('X-Request-ID')) {
            $context['request_id'] = $requestId;
        }

        // Log the API request
        $this->auditLogService->logApiRequest("{$method} {$endpoint}", $context);

        // Execute the request
        $response = $next($request);

        // Log the response
        $this->auditLogService->logApiResponse(
            "{$method} {$endpoint}",
            $response->getStatusCode(),
            [
                'status_code' => $response->getStatusCode(),
                'content_type' => $response->headers->get('Content-Type'),
                'duration_ms' => microtime(true) - $startTime,
            ]
        );

        return $response;
    }

    /**
     * Determine whether logging should be skipped for path.
     */
    private function shouldSkipPath(string $path): bool
    {
        // Skip health checks, metrics, etc.
        $skipPaths = [
            'health',
            'metrics',
            'favicon.ico',
            '_debugbar',
        ];

        foreach ($skipPaths as $skipPath) {
            if (str_starts_with($path, $skipPath)) {
                return true;
            }
        }

        return false;
    }
}
