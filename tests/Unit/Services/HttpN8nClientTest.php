<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\N8nClientInterface;
use App\DTOs\N8nWebhookPayload;
use App\Exceptions\N8nClientException;
use App\Exceptions\N8nConfigurationException;
use App\Services\HttpN8nClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class HttpN8nClientTest extends TestCase
{
    /** @var Client&MockInterface */
    private $mockHttpClient;
    private string $testWebhookUrl = 'https://test.n8n.io/webhook/test';
    private string $testAuthHeaderKey = 'X-Test-Auth';
    private string $testAuthHeaderValue = 'test-secret';

    protected function setUp(): void
    {
        parent::setUp();
        // Create a fresh mock before each test
        $this->mockHttpClient = Mockery::mock(Client::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_implements_n8n_client_interface(): void
    {
        $client = new HttpN8nClient(
            httpClient: $this->mockHttpClient,
            webhookUrl: $this->testWebhookUrl,
            authHeaderKey: $this->testAuthHeaderKey,
            authHeaderValue: $this->testAuthHeaderValue
        );

        $this->assertInstanceOf(N8nClientInterface::class, $client);
    }

    public function test_constructor_validates_empty_webhook_url(): void
    {
        $this->expectException(N8nConfigurationException::class);
        $this->expectExceptionMessage('Webhook URL is required');

        new HttpN8nClient(
            httpClient: $this->mockHttpClient,
            webhookUrl: '',
            authHeaderKey: $this->testAuthHeaderKey,
            authHeaderValue: $this->testAuthHeaderValue
        );
    }

    public function test_constructor_validates_invalid_webhook_url(): void
    {
        $this->expectException(N8nConfigurationException::class);
        $this->expectExceptionMessage('Webhook URL is invalid');

        new HttpN8nClient(
            httpClient: $this->mockHttpClient,
            webhookUrl: 'invalid-url',
            authHeaderKey: $this->testAuthHeaderKey,
            authHeaderValue: $this->testAuthHeaderValue
        );
    }

    public function test_constructor_validates_invalid_timeout(): void
    {
        $this->expectException(N8nConfigurationException::class);
        $this->expectExceptionMessage('Timeout is invalid');

        new HttpN8nClient(
            httpClient: $this->mockHttpClient,
            webhookUrl: $this->testWebhookUrl,
            authHeaderKey: $this->testAuthHeaderKey,
            authHeaderValue: $this->testAuthHeaderValue,
            timeout: 0
        );
    }

    public function test_constructor_validates_invalid_retry_attempts(): void
    {
        $this->expectException(N8nConfigurationException::class);
        $this->expectExceptionMessage('Retry attempts is invalid');

        new HttpN8nClient(
            httpClient: $this->mockHttpClient,
            webhookUrl: $this->testWebhookUrl,
            authHeaderKey: $this->testAuthHeaderKey,
            authHeaderValue: $this->testAuthHeaderValue,
            timeout: 30,
            retryAttempts: 0
        );
    }

    public function test_constructor_uses_config_values_when_not_provided(): void
    {
        // Set up all required config values - notice we're using trigger_webhook_url which is what the class looks for
        config([
            'services.n8n.trigger_webhook_url' => $this->testWebhookUrl,
            'services.n8n.auth_header_key' => $this->testAuthHeaderKey,
            'services.n8n.auth_header_value' => $this->testAuthHeaderValue,
        ]);

        $client = new HttpN8nClient($this->mockHttpClient);

        $this->assertEquals($this->testWebhookUrl, $client->getWebhookUrl());
    }

    public function test_get_webhook_url_returns_configured_url(): void
    {
        $client = new HttpN8nClient(
            httpClient: $this->mockHttpClient,
            webhookUrl: $this->testWebhookUrl,
            authHeaderKey: $this->testAuthHeaderKey,
            authHeaderValue: $this->testAuthHeaderValue
        );

        $this->assertEquals($this->testWebhookUrl, $client->getWebhookUrl());
    }

    public function test_trigger_workflow_sends_correct_request(): void
    {
        // Configure to use integration test mode to prevent test bypass
        config(['services.n8n.integration_test_mode' => true]);

        $payload = new N8nWebhookPayload('task-123', 'test script', 'test description');
        $expectedResponse = [
            'success' => true,
            'status' => 'processing',
            'message' => 'Processing started (simulated response)',
            'task_id' => 'task-123',
            'workflow_id' => 'api-test',
        ];

        // Set up the mock to expect a post request with the correct parameters
        $this->mockHttpClient
            ->shouldReceive('post')
            ->once()
            ->with(
                $this->testWebhookUrl,
                Mockery::on(function ($options) use ($payload) {
                    $this->assertArrayHasKey('headers', $options);
                    $this->assertArrayHasKey('json', $options);
                    $this->assertArrayHasKey('timeout', $options);
                    $this->assertArrayHasKey('connect_timeout', $options);

                    // Check headers
                    $headers = $options['headers'];
                    $this->assertEquals('application/json', $headers['Content-Type']);
                    $this->assertEquals('application/json', $headers['Accept']);
                    $this->assertEquals('laravel-ad-refactor', $headers['X-Source']);
                    $this->assertEquals('Laravel-N8n-Client/1.0', $headers['User-Agent']);

                    // Check payload
                    $this->assertEquals($payload->toArray(), $options['json']);

                    // Check timeouts
                    $this->assertEquals(30, $options['timeout']);
                    $this->assertEquals(10, $options['connect_timeout']);

                    return true;
                })
            )
            ->andReturn(new Response(200, [], json_encode($expectedResponse) ?: '{}'));

        $client = new HttpN8nClient(
            httpClient: $this->mockHttpClient,
            webhookUrl: $this->testWebhookUrl,
            authHeaderKey: $this->testAuthHeaderKey,
            authHeaderValue: $this->testAuthHeaderValue
        );

        $result = $client->triggerWorkflow($payload);

        $this->assertEquals($expectedResponse, $result);
    }

    public function test_trigger_workflow_includes_webhook_secret_in_headers(): void
    {
        // Configure to use integration test mode to prevent test bypass
        config(['services.n8n.integration_test_mode' => true]);

        $payload = new N8nWebhookPayload('task-123', 'test script', 'test description');

        // Set up the mock to expect a post request with authenticated headers
        $this->mockHttpClient
            ->shouldReceive('post')
            ->once()
            ->with(
                $this->testWebhookUrl,
                Mockery::on(function ($options) {
                    $headers = $options['headers'];
                    $this->assertArrayHasKey($this->testAuthHeaderKey, $headers);
                    $this->assertEquals($this->testAuthHeaderValue, $headers[$this->testAuthHeaderKey]);

                    return true;
                })
            )
            ->andReturn(new Response(200, [], '{}'));

        $client = new HttpN8nClient(
            httpClient: $this->mockHttpClient,
            webhookUrl: $this->testWebhookUrl,
            authHeaderKey: $this->testAuthHeaderKey,
            authHeaderValue: $this->testAuthHeaderValue
        );

        $client->triggerWorkflow($payload);
    }

    public function test_trigger_workflow_handles_empty_response_body(): void
    {
        // Configure to use integration test mode to prevent simulated response
        config(['services.n8n.integration_test_mode' => true]);

        $payload = new N8nWebhookPayload('task-123', 'test script', 'test description');
        $expectedResponse = [
            'success' => true,
            'workflow_id' => 'integration-test',
        ];

        // Set up the mock to return an empty response
        $this->mockHttpClient
            ->shouldReceive('post')
            ->once()
            ->andReturn(new Response(200, [], ''));

        $client = new HttpN8nClient(
            httpClient: $this->mockHttpClient,
            webhookUrl: $this->testWebhookUrl,
            authHeaderKey: $this->testAuthHeaderKey,
            authHeaderValue: $this->testAuthHeaderValue
        );

        $result = $client->triggerWorkflow($payload);

        // In integration test mode with empty response, we get default values with integration-test workflow_id
        $this->assertEquals($expectedResponse, $result);
    }

    public function test_trigger_workflow_handles_invalid_json_response(): void
    {
        // Configure to use integration test mode to prevent simulated response
        config(['services.n8n.integration_test_mode' => true]);

        $payload = new N8nWebhookPayload('task-123', 'test script', 'test description');
        $expectedResponse = [
            'success' => true,
            'workflow_id' => 'integration-test',
        ];

        // Set up the mock to return invalid JSON
        $this->mockHttpClient
            ->shouldReceive('post')
            ->once()
            ->andReturn(new Response(200, [], 'invalid json'));

        $client = new HttpN8nClient(
            httpClient: $this->mockHttpClient,
            webhookUrl: $this->testWebhookUrl,
            authHeaderKey: $this->testAuthHeaderKey,
            authHeaderValue: $this->testAuthHeaderValue
        );

        $result = $client->triggerWorkflow($payload);

        // In integration test mode with invalid JSON, we get default values with integration-test workflow_id
        $this->assertEquals($expectedResponse, $result);
    }

    public function test_trigger_workflow_retries_on_connect_exception(): void
    {
        // Configure to use integration test mode to prevent test bypass
        config(['services.n8n.integration_test_mode' => true]);

        $payload = new N8nWebhookPayload('task-123', 'test script', 'test description');
        $connectException = new ConnectException('Connection failed', new Request('POST', $this->testWebhookUrl));

        // Set up the mock to throw a ConnectException three times
        $this->mockHttpClient
            ->shouldReceive('post')
            ->times(3)
            ->andThrow($connectException);

        $client = new HttpN8nClient(
            httpClient: $this->mockHttpClient,
            webhookUrl: $this->testWebhookUrl,
            authHeaderKey: $this->testAuthHeaderKey,
            authHeaderValue: $this->testAuthHeaderValue,
            timeout: 30,
            retryAttempts: 3,
            retryDelays: [0, 0, 0]
        );

        $this->expectException(N8nClientException::class);
        $this->expectExceptionMessage(
            sprintf(
                "Failed to connect to n8n webhook at %s: Connection failed",
                $this->testWebhookUrl
            )
        );
        $client->triggerWorkflow($payload);
    }

    public function test_trigger_workflow_retries_on_request_exception_with_response(): void
    {
        // Configure to use integration test mode to prevent test bypass
        config(['services.n8n.integration_test_mode' => true]);

        $payload = new N8nWebhookPayload('task-123', 'test script', 'test description');

        // Create a mock response with a 429 Too Many Requests status
        $mockResponse = \Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
        $mockResponse->shouldReceive('getStatusCode')->andReturn(429);
        $mockResponse->shouldReceive('getBody->getContents')->andReturn('{"error": "Too Many Requests"}');

        // Create a RequestException with the mock response
        $requestException = new RequestException(
            'Too Many Requests',
            new Request('POST', $this->testWebhookUrl),
            $mockResponse
        );

        // Set up the mock to throw a RequestException with response three times
        $this->mockHttpClient
            ->shouldReceive('post')
            ->times(3)  // Should retry 3 times
            ->andThrow($requestException);

        $client = new HttpN8nClient(
            httpClient: $this->mockHttpClient,
            webhookUrl: $this->testWebhookUrl,
            authHeaderKey: $this->testAuthHeaderKey,
            authHeaderValue: $this->testAuthHeaderValue,
            timeout: 30,
            retryAttempts: 3,
            retryDelays: [0, 0, 0]  // Use 0 to speed up the test
        );

        $this->expectException(N8nClientException::class);
        $this->expectExceptionMessage('N8n webhook returned HTTP 429: {"error": "Too Many Requests"}');
        $client->triggerWorkflow($payload);
    }

    public function test_trigger_workflow_retries_on_transfer_exception(): void
    {
        // Configure to use integration test mode to prevent test bypass
        config(['services.n8n.integration_test_mode' => true]);

        $payload = new N8nWebhookPayload('task-123', 'test script', 'test description');
        $transferException = new TransferException('Transfer failed');

        // Set up the mock to throw a TransferException three times
        $this->mockHttpClient
            ->shouldReceive('post')
            ->times(3)  // Should retry 3 times
            ->andThrow($transferException);

        $client = new HttpN8nClient(
            httpClient: $this->mockHttpClient,
            webhookUrl: $this->testWebhookUrl,
            authHeaderKey: $this->testAuthHeaderKey,
            authHeaderValue: $this->testAuthHeaderValue,
            timeout: 30,
            retryAttempts: 3,
            retryDelays: [0, 0, 0]  // Use 0 to speed up the test
        );

        $this->expectException(N8nClientException::class);
        $this->expectExceptionMessage(
            sprintf(
                "Failed to connect to n8n webhook at %s: Transfer failed",
                $this->testWebhookUrl
            )
        );
        $client->triggerWorkflow($payload);
    }

    public function test_trigger_workflow_succeeds_after_retries(): void
    {
        // Configure to use integration test mode to prevent test bypass
        config(['services.n8n.integration_test_mode' => true]);

        $payload = new N8nWebhookPayload('task-123', 'test script', 'test description');
        $connectException = new ConnectException('Connection failed', new Request('POST', $this->testWebhookUrl));
        $expectedResponse = [
            'success' => true,
            'workflow_id' => 'test-workflow',
        ];

        // First attempt fails, second attempt succeeds
        $this->mockHttpClient
            ->shouldReceive('post')
            ->once()
            ->andThrow($connectException);

        // Create a successful response
        $mockResponse = \Mockery::mock(\Psr\Http\Message\ResponseInterface::class);
        $mockResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockResponse->shouldReceive('getBody->getContents')->andReturn(json_encode($expectedResponse));

        $this->mockHttpClient
            ->shouldReceive('post')
            ->once()
            ->andReturn($mockResponse);

        $client = new HttpN8nClient(
            httpClient: $this->mockHttpClient,
            webhookUrl: $this->testWebhookUrl,
            authHeaderKey: $this->testAuthHeaderKey,
            authHeaderValue: $this->testAuthHeaderValue,
            timeout: 30,
            retryAttempts: 3,
            retryDelays: [0, 0, 0]  // Use 0 to speed up the test
        );

        $result = $client->triggerWorkflow($payload);
        $this->assertEquals($expectedResponse, $result);
    }

    public function test_is_available_returns_true_for_successful_response(): void
    {
        // Expect a GET request with our health check configuration
        $this->mockHttpClient
            ->shouldReceive('get')
            ->once()
            ->with(
                $this->testWebhookUrl,
                Mockery::on(function ($options) {
                    $this->assertEquals(5, $options['timeout']);
                    $this->assertEquals(3, $options['connect_timeout']);
                    $this->assertFalse($options['http_errors']);

                    return true;
                })
            )
            ->andReturn(new Response(200));

        $client = new HttpN8nClient(
            httpClient: $this->mockHttpClient,
            webhookUrl: $this->testWebhookUrl,
            authHeaderKey: $this->testAuthHeaderKey,
            authHeaderValue: $this->testAuthHeaderValue
        );

        $result = $client->isAvailable();

        $this->assertTrue($result);
    }

    public function test_is_available_returns_true_for_client_error_response(): void
    {
        // Expect a GET request that returns 404
        $this->mockHttpClient
            ->shouldReceive('get')
            ->once()
            ->andReturn(new Response(404));

        $client = new HttpN8nClient(
            httpClient: $this->mockHttpClient,
            webhookUrl: $this->testWebhookUrl,
            authHeaderKey: $this->testAuthHeaderKey,
            authHeaderValue: $this->testAuthHeaderValue
        );

        $result = $client->isAvailable();

        $this->assertTrue($result);
    }

    public function test_is_available_returns_false_for_server_error_response(): void
    {
        // Expect a GET request that returns 500
        $this->mockHttpClient
            ->shouldReceive('get')
            ->once()
            ->andReturn(new Response(500));

        $client = new HttpN8nClient(
            httpClient: $this->mockHttpClient,
            webhookUrl: $this->testWebhookUrl,
            authHeaderKey: $this->testAuthHeaderKey,
            authHeaderValue: $this->testAuthHeaderValue
        );

        $result = $client->isAvailable();

        $this->assertFalse($result);
    }

    public function test_is_available_returns_false_for_transfer_exception(): void
    {
        // Expect a GET request that throws an exception
        $this->mockHttpClient
            ->shouldReceive('get')
            ->once()
            ->andThrow(new TransferException('Connection failed'));

        $client = new HttpN8nClient(
            httpClient: $this->mockHttpClient,
            webhookUrl: $this->testWebhookUrl,
            authHeaderKey: $this->testAuthHeaderKey,
            authHeaderValue: $this->testAuthHeaderValue
        );

        $result = $client->isAvailable();

        $this->assertFalse($result);
    }

    public function test_trigger_workflow_timeout_throws_exception(): void
    {
        // Configure to use integration test mode to prevent test bypass
        config(['services.n8n.integration_test_mode' => true]);

        $payload = new N8nWebhookPayload('task-123', 'test script', 'test description');

        // Create a mock exception representing a timeout
        $timeoutException = new TransferException('cURL error 28: Operation timed out');

        // Set up the mock to throw a timeout exception
        $this->mockHttpClient
            ->shouldReceive('post')
            ->times(3)
            ->andThrow($timeoutException);

        $client = new HttpN8nClient(
            httpClient: $this->mockHttpClient,
            webhookUrl: $this->testWebhookUrl,
            authHeaderKey: $this->testAuthHeaderKey,
            authHeaderValue: $this->testAuthHeaderValue,
            timeout: 30,
            retryAttempts: 3,
            retryDelays: [0, 0, 0]  // Use 0 to speed up the test
        );

        $this->expectException(N8nClientException::class);
        $this->expectExceptionMessage(
            sprintf(
                "Failed to connect to n8n webhook at %s: cURL error 28: Operation timed out",
                $this->testWebhookUrl
            )
        );
        $client->triggerWorkflow($payload);
    }

    public function test_custom_timeout_and_retry_configuration(): void
    {
        // Configure to use integration test mode to prevent test bypass
        config(['services.n8n.integration_test_mode' => true]);

        $payload = new N8nWebhookPayload('task-123', 'test script', 'test description');
        $expectedResponse = [
            'success' => true,
            'workflow_id' => 'integration-test',
        ];

        // Expect a post request with our custom timeout
        $this->mockHttpClient
            ->shouldReceive('post')
            ->once()
            ->with(
                $this->testWebhookUrl,
                Mockery::on(function ($options) {
                    $this->assertEquals(60, $options['timeout']);

                    return true;
                })
            )
            ->andReturn(new Response(200, [], '{}'));

        $client = new HttpN8nClient(
            httpClient: $this->mockHttpClient,
            webhookUrl: $this->testWebhookUrl,
            authHeaderKey: $this->testAuthHeaderKey,
            authHeaderValue: $this->testAuthHeaderValue,
            timeout: 60, // custom timeout
            retryAttempts: 1,  // single attempt
            retryDelays: [0]
        );

        $result = $client->triggerWorkflow($payload);
        $this->assertEquals($expectedResponse, $result);
    }
}
