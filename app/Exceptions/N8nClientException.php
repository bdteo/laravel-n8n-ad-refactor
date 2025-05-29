<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Exception thrown when n8n client operations fail.
 */
class N8nClientException extends Exception
{
    /**
     * Create a new exception for connection failures.
     */
    public static function connectionFailed(string $url, string $reason): self
    {
        return new self("Failed to connect to n8n webhook at {$url}: {$reason}");
    }

    /**
     * Create a new exception for HTTP errors.
     */
    public static function httpError(int $statusCode, string $response): self
    {
        return new self("N8n webhook returned HTTP {$statusCode}: {$response}");
    }

    /**
     * Create a new exception for configuration errors.
     */
    public static function configurationError(string $message): self
    {
        return new self("N8n client configuration error: {$message}");
    }

    /**
     * Create a new exception for timeout errors.
     */
    public static function timeout(string $url): self
    {
        return new self("Request to n8n webhook at {$url} timed out");
    }

    /**
     * Create a new exception for invalid responses.
     */
    public static function invalidResponse(string $reason): self
    {
        return new self("Invalid response from n8n: {$reason}");
    }
}
