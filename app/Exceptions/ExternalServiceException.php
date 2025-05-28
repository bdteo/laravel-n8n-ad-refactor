<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Exception thrown when external service operations fail.
 */
class ExternalServiceException extends Exception
{
    private string $serviceName;
    private ?string $serviceUrl;
    private ?int $httpStatusCode;
    private ?string $serviceResponse;

    public function __construct(
        string $message,
        string $serviceName,
        ?string $serviceUrl = null,
        ?int $httpStatusCode = null,
        ?string $serviceResponse = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->serviceName = $serviceName;
        $this->serviceUrl = $serviceUrl;
        $this->httpStatusCode = $httpStatusCode;
        $this->serviceResponse = $serviceResponse;
    }

    /**
     * Get the service name.
     */
    public function getServiceName(): string
    {
        return $this->serviceName;
    }

    /**
     * Get the service URL.
     */
    public function getServiceUrl(): ?string
    {
        return $this->serviceUrl;
    }

    /**
     * Get the HTTP status code.
     */
    public function getHttpStatusCode(): ?int
    {
        return $this->httpStatusCode;
    }

    /**
     * Get the service response.
     */
    public function getServiceResponse(): ?string
    {
        return $this->serviceResponse;
    }

    /**
     * Create a new exception for service unavailability.
     */
    public static function serviceUnavailable(string $serviceName, ?string $serviceUrl = null): self
    {
        return new self(
            "Service '{$serviceName}' is currently unavailable",
            $serviceName,
            $serviceUrl
        );
    }

    /**
     * Create a new exception for authentication failures.
     */
    public static function authenticationFailed(string $serviceName, ?string $serviceUrl = null): self
    {
        return new self(
            "Authentication failed for service '{$serviceName}'",
            $serviceName,
            $serviceUrl,
            401
        );
    }

    /**
     * Create a new exception for rate limiting.
     */
    public static function rateLimited(string $serviceName, ?string $serviceUrl = null, ?int $retryAfter = null): self
    {
        $message = "Rate limit exceeded for service '{$serviceName}'";
        if ($retryAfter) {
            $message .= " (retry after {$retryAfter} seconds)";
        }

        return new self(
            $message,
            $serviceName,
            $serviceUrl,
            429
        );
    }

    /**
     * Create a new exception for timeout errors.
     */
    public static function timeout(string $serviceName, ?string $serviceUrl = null, int $timeoutSeconds = 30): self
    {
        return new self(
            "Request to service '{$serviceName}' timed out after {$timeoutSeconds} seconds",
            $serviceName,
            $serviceUrl
        );
    }

    /**
     * Create a new exception for HTTP errors.
     */
    public static function httpError(
        string $serviceName,
        int $statusCode,
        ?string $serviceUrl = null,
        ?string $response = null
    ): self {
        return new self(
            "HTTP {$statusCode} error from service '{$serviceName}'",
            $serviceName,
            $serviceUrl,
            $statusCode,
            $response
        );
    }

    /**
     * Create a new exception for invalid responses.
     */
    public static function invalidResponse(
        string $serviceName,
        string $reason,
        ?string $serviceUrl = null,
        ?string $response = null
    ): self {
        return new self(
            "Invalid response from service '{$serviceName}': {$reason}",
            $serviceName,
            $serviceUrl,
            null,
            $response
        );
    }

    /**
     * Create a new exception for configuration errors.
     */
    public static function configurationError(string $serviceName, string $reason): self
    {
        return new self(
            "Configuration error for service '{$serviceName}': {$reason}",
            $serviceName
        );
    }
}
