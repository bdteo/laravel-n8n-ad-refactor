<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use App\Exceptions\ExternalServiceException;
use PHPUnit\Framework\TestCase;

class ExternalServiceExceptionTest extends TestCase
{
    public function test_constructor_sets_all_properties(): void
    {
        $message = 'Service error';
        $serviceName = 'test-service';
        $serviceUrl = 'https://api.test.com';
        $httpStatusCode = 500;
        $serviceResponse = 'Internal server error';

        $exception = new ExternalServiceException(
            $message,
            $serviceName,
            $serviceUrl,
            $httpStatusCode,
            $serviceResponse
        );

        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals($serviceName, $exception->getServiceName());
        $this->assertEquals($serviceUrl, $exception->getServiceUrl());
        $this->assertEquals($httpStatusCode, $exception->getHttpStatusCode());
        $this->assertEquals($serviceResponse, $exception->getServiceResponse());
    }

    public function test_constructor_with_minimal_parameters(): void
    {
        $message = 'Service error';
        $serviceName = 'test-service';

        $exception = new ExternalServiceException($message, $serviceName);

        $this->assertEquals($message, $exception->getMessage());
        $this->assertEquals($serviceName, $exception->getServiceName());
        $this->assertNull($exception->getServiceUrl());
        $this->assertNull($exception->getHttpStatusCode());
        $this->assertNull($exception->getServiceResponse());
    }

    public function test_service_unavailable_creates_exception(): void
    {
        $serviceName = 'payment-gateway';
        $serviceUrl = 'https://api.payment.com';
        $exception = ExternalServiceException::serviceUnavailable($serviceName, $serviceUrl);

        $this->assertInstanceOf(ExternalServiceException::class, $exception);
        $this->assertEquals("Service '{$serviceName}' is currently unavailable", $exception->getMessage());
        $this->assertEquals($serviceName, $exception->getServiceName());
        $this->assertEquals($serviceUrl, $exception->getServiceUrl());
    }

    public function test_service_unavailable_without_url(): void
    {
        $serviceName = 'email-service';
        $exception = ExternalServiceException::serviceUnavailable($serviceName);

        $this->assertEquals("Service '{$serviceName}' is currently unavailable", $exception->getMessage());
        $this->assertEquals($serviceName, $exception->getServiceName());
        $this->assertNull($exception->getServiceUrl());
    }

    public function test_authentication_failed_creates_exception(): void
    {
        $serviceName = 'oauth-provider';
        $serviceUrl = 'https://oauth.example.com';
        $exception = ExternalServiceException::authenticationFailed($serviceName, $serviceUrl);

        $this->assertInstanceOf(ExternalServiceException::class, $exception);
        $this->assertEquals("Authentication failed for service '{$serviceName}'", $exception->getMessage());
        $this->assertEquals($serviceName, $exception->getServiceName());
        $this->assertEquals($serviceUrl, $exception->getServiceUrl());
        $this->assertEquals(401, $exception->getHttpStatusCode());
    }

    public function test_rate_limited_creates_exception(): void
    {
        $serviceName = 'api-service';
        $serviceUrl = 'https://api.example.com';
        $retryAfter = 60;
        $exception = ExternalServiceException::rateLimited($serviceName, $serviceUrl, $retryAfter);

        $this->assertInstanceOf(ExternalServiceException::class, $exception);
        $this->assertEquals(
            "Rate limit exceeded for service '{$serviceName}' (retry after {$retryAfter} seconds)",
            $exception->getMessage()
        );
        $this->assertEquals($serviceName, $exception->getServiceName());
        $this->assertEquals($serviceUrl, $exception->getServiceUrl());
        $this->assertEquals(429, $exception->getHttpStatusCode());
    }

    public function test_rate_limited_without_retry_after(): void
    {
        $serviceName = 'api-service';
        $exception = ExternalServiceException::rateLimited($serviceName);

        $this->assertEquals(
            "Rate limit exceeded for service '{$serviceName}'",
            $exception->getMessage()
        );
        $this->assertEquals(429, $exception->getHttpStatusCode());
    }

    public function test_timeout_creates_exception(): void
    {
        $serviceName = 'slow-service';
        $serviceUrl = 'https://slow.example.com';
        $timeoutSeconds = 30;
        $exception = ExternalServiceException::timeout($serviceName, $serviceUrl, $timeoutSeconds);

        $this->assertInstanceOf(ExternalServiceException::class, $exception);
        $this->assertEquals(
            "Request to service '{$serviceName}' timed out after {$timeoutSeconds} seconds",
            $exception->getMessage()
        );
        $this->assertEquals($serviceName, $exception->getServiceName());
        $this->assertEquals($serviceUrl, $exception->getServiceUrl());
    }

    public function test_http_error_creates_exception(): void
    {
        $serviceName = 'web-service';
        $statusCode = 404;
        $serviceUrl = 'https://web.example.com';
        $response = 'Not found';
        $exception = ExternalServiceException::httpError($serviceName, $statusCode, $serviceUrl, $response);

        $this->assertInstanceOf(ExternalServiceException::class, $exception);
        $this->assertEquals("HTTP {$statusCode} error from service '{$serviceName}'", $exception->getMessage());
        $this->assertEquals($serviceName, $exception->getServiceName());
        $this->assertEquals($serviceUrl, $exception->getServiceUrl());
        $this->assertEquals($statusCode, $exception->getHttpStatusCode());
        $this->assertEquals($response, $exception->getServiceResponse());
    }

    public function test_invalid_response_creates_exception(): void
    {
        $serviceName = 'json-service';
        $reason = 'Invalid JSON format';
        $serviceUrl = 'https://json.example.com';
        $response = '{"invalid": json}';
        $exception = ExternalServiceException::invalidResponse($serviceName, $reason, $serviceUrl, $response);

        $this->assertInstanceOf(ExternalServiceException::class, $exception);
        $this->assertEquals("Invalid response from service '{$serviceName}': {$reason}", $exception->getMessage());
        $this->assertEquals($serviceName, $exception->getServiceName());
        $this->assertEquals($serviceUrl, $exception->getServiceUrl());
        $this->assertEquals($response, $exception->getServiceResponse());
    }

    public function test_configuration_error_creates_exception(): void
    {
        $serviceName = 'config-service';
        $reason = 'Missing API key';
        $exception = ExternalServiceException::configurationError($serviceName, $reason);

        $this->assertInstanceOf(ExternalServiceException::class, $exception);
        $this->assertEquals("Configuration error for service '{$serviceName}': {$reason}", $exception->getMessage());
        $this->assertEquals($serviceName, $exception->getServiceName());
    }
}
