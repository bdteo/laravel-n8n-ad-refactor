<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\N8nClientInterface;
use App\DTOs\N8nWebhookPayload;
use App\Exceptions\N8nClientException;
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
    private string $testWebhookSecret = 'test-secret';

    protected function setUp(): void
    {
        parent::setUp();
        /** @var Client&MockInterface $mockHttpClient */
        $mockHttpClient = Mockery::mock(Client::class);
        $this->mockHttpClient = $mockHttpClient;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_implements_n8n_client_interface(): void
    {
        $client = new HttpN8nClient($this->mockHttpClient, $this->testWebhookUrl);

        $this->assertInstanceOf(N8nClientInterface::class, $client);
    }

    public function test_constructor_validates_empty_webhook_url(): void
    {
        $this->expectException(N8nClientException::class);
        $this->expectExceptionMessage('Webhook URL is required');

        new HttpN8nClient($this->mockHttpClient, '');
    }

    public function test_constructor_validates_invalid_webhook_url(): void
    {
        $this->expectException(N8nClientException::class);
        $this->expectExceptionMessage('Webhook URL is not valid');

        new HttpN8nClient($this->mockHttpClient, 'invalid-url');
    }

    public function test_constructor_validates_invalid_timeout(): void
    {
        $this->expectException(N8nClientException::class);
        $this->expectExceptionMessage('Timeout must be greater than 0');

        new HttpN8nClient($this->mockHttpClient, $this->testWebhookUrl, null, 0);
    }

    public function test_constructor_validates_invalid_retry_attempts(): void
    {
        $this->expectException(N8nClientException::class);
        $this->expectExceptionMessage('Retry attempts must be at least 1');

        new HttpN8nClient($this->mockHttpClient, $this->testWebhookUrl, null, 30, 0);
    }

    public function test_constructor_uses_config_values_when_not_provided(): void
    {
        config(['services.n8n.webhook_url' => $this->testWebhookUrl]);
        config(['services.n8n.webhook_secret' => $this->testWebhookSecret]);

        $client = new HttpN8nClient($this->mockHttpClient);

        $this->assertEquals($this->testWebhookUrl, $client->getWebhookUrl());
    }

    public function test_get_webhook_url_returns_configured_url(): void
    {
        $client = new HttpN8nClient($this->mockHttpClient, $this->testWebhookUrl);

        $this->assertEquals($this->testWebhookUrl, $client->getWebhookUrl());
    }

    public function test_trigger_workflow_sends_correct_request(): void
    {
        $payload = new N8nWebhookPayload('task-123', 'test script', 'test description');
        $expectedResponse = ['status' => 'success'];

        $this->mockHttpClient
            ->shouldReceive('post')
            ->once()
            ->with($this->testWebhookUrl, Mockery::on(function ($options) use ($payload) {
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
            }))
            ->andReturn(new Response(200, [], json_encode($expectedResponse) ?: '{}'));

        $client = new HttpN8nClient($this->mockHttpClient, $this->testWebhookUrl);
        $result = $client->triggerWorkflow($payload);

        $this->assertEquals($expectedResponse, $result);
    }

    public function test_trigger_workflow_includes_webhook_secret_in_headers(): void
    {
        $payload = new N8nWebhookPayload('task-123', 'test script', 'test description');

        $this->mockHttpClient
            ->shouldReceive('post')
            ->once()
            ->with($this->testWebhookUrl, Mockery::on(function ($options) {
                $headers = $options['headers'];
                $this->assertArrayHasKey('X-Webhook-Secret', $headers);
                $this->assertEquals($this->testWebhookSecret, $headers['X-Webhook-Secret']);

                return true;
            }))
            ->andReturn(new Response(200, [], '{}'));

        $client = new HttpN8nClient($this->mockHttpClient, $this->testWebhookUrl, $this->testWebhookSecret);
        $client->triggerWorkflow($payload);
    }

    public function test_trigger_workflow_handles_empty_response_body(): void
    {
        $payload = new N8nWebhookPayload('task-123', 'test script', 'test description');

        $this->mockHttpClient
            ->shouldReceive('post')
            ->once()
            ->andReturn(new Response(200, [], ''));

        $client = new HttpN8nClient($this->mockHttpClient, $this->testWebhookUrl);
        $result = $client->triggerWorkflow($payload);

        $this->assertEquals([], $result);
    }

    public function test_trigger_workflow_handles_invalid_json_response(): void
    {
        $payload = new N8nWebhookPayload('task-123', 'test script', 'test description');

        $this->mockHttpClient
            ->shouldReceive('post')
            ->once()
            ->andReturn(new Response(200, [], 'invalid json'));

        $client = new HttpN8nClient($this->mockHttpClient, $this->testWebhookUrl);
        $result = $client->triggerWorkflow($payload);

        $this->assertEquals([], $result);
    }

    public function test_trigger_workflow_retries_on_connect_exception(): void
    {
        $payload = new N8nWebhookPayload('task-123', 'test script', 'test description');
        $connectException = new ConnectException('Connection failed', new Request('POST', $this->testWebhookUrl));

        $this->mockHttpClient
            ->shouldReceive('post')
            ->times(3)
            ->andThrow($connectException);

        $client = new HttpN8nClient($this->mockHttpClient, $this->testWebhookUrl, null, 30, 3, [0, 0, 0]);

        $this->expectException(N8nClientException::class);
        $this->expectExceptionMessage('Failed to connect to n8n webhook');

        $client->triggerWorkflow($payload);
    }

    public function test_trigger_workflow_retries_on_request_exception_with_response(): void
    {
        $payload = new N8nWebhookPayload('task-123', 'test script', 'test description');
        $response = new Response(500, [], 'Internal Server Error');
        $requestException = new RequestException('Server error', new Request('POST', $this->testWebhookUrl), $response);

        $this->mockHttpClient
            ->shouldReceive('post')
            ->times(3)
            ->andThrow($requestException);

        $client = new HttpN8nClient($this->mockHttpClient, $this->testWebhookUrl, null, 30, 3, [0, 0, 0]);

        $this->expectException(N8nClientException::class);
        $this->expectExceptionMessage('N8n webhook returned HTTP 500');

        $client->triggerWorkflow($payload);
    }

    public function test_trigger_workflow_retries_on_request_exception_without_response(): void
    {
        $payload = new N8nWebhookPayload('task-123', 'test script', 'test description');
        $requestException = new RequestException('Timeout', new Request('POST', $this->testWebhookUrl));

        $this->mockHttpClient
            ->shouldReceive('post')
            ->times(3)
            ->andThrow($requestException);

        $client = new HttpN8nClient($this->mockHttpClient, $this->testWebhookUrl, null, 30, 3, [0, 0, 0]);

        $this->expectException(N8nClientException::class);
        $this->expectExceptionMessage('Request to n8n webhook at');

        $client->triggerWorkflow($payload);
    }

    public function test_trigger_workflow_retries_on_transfer_exception(): void
    {
        $payload = new N8nWebhookPayload('task-123', 'test script', 'test description');
        $transferException = new TransferException('Transfer failed');

        $this->mockHttpClient
            ->shouldReceive('post')
            ->times(3)
            ->andThrow($transferException);

        $client = new HttpN8nClient($this->mockHttpClient, $this->testWebhookUrl, null, 30, 3, [0, 0, 0]);

        $this->expectException(N8nClientException::class);
        $this->expectExceptionMessage('Failed to connect to n8n webhook');

        $client->triggerWorkflow($payload);
    }

    public function test_trigger_workflow_succeeds_after_retry(): void
    {
        $payload = new N8nWebhookPayload('task-123', 'test script', 'test description');
        $connectException = new ConnectException('Connection failed', new Request('POST', $this->testWebhookUrl));
        $expectedResponse = ['status' => 'success'];

        $this->mockHttpClient
            ->shouldReceive('post')
            ->twice()
            ->andThrow($connectException)
            ->shouldReceive('post')
            ->once()
            ->andReturn(new Response(200, [], json_encode($expectedResponse) ?: '{}'));

        $client = new HttpN8nClient($this->mockHttpClient, $this->testWebhookUrl, null, 30, 3, [0, 0, 0]);
        $result = $client->triggerWorkflow($payload);

        $this->assertEquals($expectedResponse, $result);
    }

    public function test_is_available_returns_true_for_successful_response(): void
    {
        $this->mockHttpClient
            ->shouldReceive('get')
            ->once()
            ->with($this->testWebhookUrl, Mockery::on(function ($options) {
                $this->assertEquals(5, $options['timeout']);
                $this->assertEquals(3, $options['connect_timeout']);
                $this->assertFalse($options['http_errors']);

                return true;
            }))
            ->andReturn(new Response(200));

        $client = new HttpN8nClient($this->mockHttpClient, $this->testWebhookUrl);
        $result = $client->isAvailable();

        $this->assertTrue($result);
    }

    public function test_is_available_returns_true_for_client_error_response(): void
    {
        $this->mockHttpClient
            ->shouldReceive('get')
            ->once()
            ->andReturn(new Response(404));

        $client = new HttpN8nClient($this->mockHttpClient, $this->testWebhookUrl);
        $result = $client->isAvailable();

        $this->assertTrue($result);
    }

    public function test_is_available_returns_false_for_server_error_response(): void
    {
        $this->mockHttpClient
            ->shouldReceive('get')
            ->once()
            ->andReturn(new Response(500));

        $client = new HttpN8nClient($this->mockHttpClient, $this->testWebhookUrl);
        $result = $client->isAvailable();

        $this->assertFalse($result);
    }

    public function test_is_available_returns_false_for_transfer_exception(): void
    {
        $this->mockHttpClient
            ->shouldReceive('get')
            ->once()
            ->andThrow(new TransferException('Connection failed'));

        $client = new HttpN8nClient($this->mockHttpClient, $this->testWebhookUrl);
        $result = $client->isAvailable();

        $this->assertFalse($result);
    }

    public function test_custom_timeout_and_retry_configuration(): void
    {
        $payload = new N8nWebhookPayload('task-123', 'test script', 'test description');

        $this->mockHttpClient
            ->shouldReceive('post')
            ->once()
            ->with($this->testWebhookUrl, Mockery::on(function ($options) {
                $this->assertEquals(60, $options['timeout']);

                return true;
            }))
            ->andReturn(new Response(200, [], '{}'));

        $client = new HttpN8nClient(
            $this->mockHttpClient,
            $this->testWebhookUrl,
            null,
            60, // custom timeout
            1,  // single attempt
            []
        );

        $client->triggerWorkflow($payload);
    }
}
