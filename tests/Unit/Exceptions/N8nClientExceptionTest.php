<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use App\Exceptions\N8nClientException;
use Exception;
use Tests\TestCase;

class N8nClientExceptionTest extends TestCase
{
    public function test_extends_exception(): void
    {
        $exception = new N8nClientException('Test message');

        $this->assertInstanceOf(Exception::class, $exception);
    }

    public function test_connection_failed_creates_exception_with_correct_message(): void
    {
        $url = 'https://test.n8n.io/webhook/test';
        $reason = 'Connection timeout';

        $exception = N8nClientException::connectionFailed($url, $reason);

        $this->assertInstanceOf(N8nClientException::class, $exception);
        $this->assertEquals(
            "Failed to connect to n8n webhook at {$url}: {$reason}",
            $exception->getMessage()
        );
    }

    public function test_http_error_creates_exception_with_correct_message(): void
    {
        $statusCode = 500;
        $response = 'Internal Server Error';

        $exception = N8nClientException::httpError($statusCode, $response);

        $this->assertInstanceOf(N8nClientException::class, $exception);
        $this->assertEquals(
            "N8n webhook returned HTTP {$statusCode}: {$response}",
            $exception->getMessage()
        );
    }

    public function test_configuration_error_creates_exception_with_correct_message(): void
    {
        $message = 'Webhook URL is required';

        $exception = N8nClientException::configurationError($message);

        $this->assertInstanceOf(N8nClientException::class, $exception);
        $this->assertEquals(
            "N8n client configuration error: {$message}",
            $exception->getMessage()
        );
    }

    public function test_timeout_creates_exception_with_correct_message(): void
    {
        $url = 'https://test.n8n.io/webhook/test';

        $exception = N8nClientException::timeout($url);

        $this->assertInstanceOf(N8nClientException::class, $exception);
        $this->assertEquals(
            "Request to n8n webhook at {$url} timed out",
            $exception->getMessage()
        );
    }
}
