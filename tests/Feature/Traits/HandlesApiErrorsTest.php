<?php

declare(strict_types=1);

namespace Tests\Feature\Traits;

use App\Exceptions\AdScriptTaskException;
use App\Exceptions\BusinessValidationException;
use App\Exceptions\ExternalServiceException;
use App\Exceptions\N8nClientException;
use App\Traits\HandlesApiErrors;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Tests\TestCase;

class HandlesApiErrorsTest extends TestCase
{
    use RefreshDatabase;

    private object $traitObject;

    protected function setUp(): void
    {
        parent::setUp();

        // Create an anonymous class that uses the trait and makes methods public
        $this->traitObject = new class () {
            use HandlesApiErrors {
                handleException as public;
                validationErrorResponse as public;
                notFoundResponse as public;
                taskErrorResponse as public;
                serviceErrorResponse as public;
                serverErrorResponse as public;
                successResponse as public;
                createdResponse as public;
                acceptedResponse as public;
            }
        };
    }

    public function test_handle_business_validation_exception(): void
    {
        $errors = ['email' => 'Invalid email'];
        $exception = new BusinessValidationException('Validation failed', $errors);

        $response = $this->traitObject->handleException($exception, 'test context');

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(422, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertTrue($data['error']);
        $this->assertEquals('validation_error', $data['type']);
        $this->assertEquals($errors, $data['errors']);
    }

    public function test_handle_model_not_found_exception(): void
    {
        $exception = new ModelNotFoundException();

        $response = $this->traitObject->handleException($exception, 'test context');

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(404, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertTrue($data['error']);
        $this->assertEquals('not_found', $data['type']);
        $this->assertEquals('Resource not found', $data['message']);
    }

    public function test_handle_ad_script_task_exception(): void
    {
        $exception = AdScriptTaskException::notFound('task-123');

        $response = $this->traitObject->handleException($exception, 'test context');

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(404, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertTrue($data['error']);
        $this->assertEquals('task_error', $data['type']);
    }

    public function test_handle_ad_script_task_exception_non_not_found(): void
    {
        $exception = AdScriptTaskException::processingFailed('task-123', 'Invalid data');

        $response = $this->traitObject->handleException($exception, 'test context');

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(422, $response->getStatusCode());
    }

    public function test_handle_n8n_client_exception(): void
    {
        $exception = N8nClientException::connectionFailed('https://test.com', 'timeout');

        $response = $this->traitObject->handleException($exception, 'test context');

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(503, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertTrue($data['error']);
        $this->assertEquals('service_error', $data['type']);
        $this->assertEquals('n8n', $data['service']);
        $this->assertEquals(30, $data['retry_after']);
    }

    public function test_handle_external_service_exception(): void
    {
        $exception = ExternalServiceException::serviceUnavailable('payment-service');

        $response = $this->traitObject->handleException($exception, 'test context');

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(503, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertTrue($data['error']);
        $this->assertEquals('service_error', $data['type']);
        $this->assertEquals('payment-service', $data['service']);
    }

    public function test_handle_generic_exception(): void
    {
        $exception = new \Exception('Generic error');

        $response = $this->traitObject->handleException($exception, 'test context');

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(500, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertTrue($data['error']);
        $this->assertEquals('server_error', $data['type']);
    }

    public function test_success_response(): void
    {
        $data = ['id' => 1, 'name' => 'test'];
        $message = 'Success message';

        $response = $this->traitObject->successResponse($data, $message);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $responseData = $response->getData(true);
        $this->assertFalse($responseData['error']);
        $this->assertEquals($message, $responseData['message']);
        $this->assertEquals($data, $responseData['data']);
        $this->assertArrayHasKey('timestamp', $responseData);
    }

    public function test_created_response(): void
    {
        $data = ['id' => 1];

        $response = $this->traitObject->createdResponse($data);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(201, $response->getStatusCode());

        $responseData = $response->getData(true);
        $this->assertEquals('Resource created successfully', $responseData['message']);
    }

    public function test_accepted_response(): void
    {
        $data = ['id' => 1];

        $response = $this->traitObject->acceptedResponse($data);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(202, $response->getStatusCode());

        $responseData = $response->getData(true);
        $this->assertEquals('Request accepted for processing', $responseData['message']);
    }

    public function test_not_found_response(): void
    {
        $message = 'Custom not found message';

        $response = $this->traitObject->notFoundResponse($message);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(404, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertTrue($data['error']);
        $this->assertEquals('not_found', $data['type']);
        $this->assertEquals($message, $data['message']);
    }

    public function test_validation_error_response(): void
    {
        $errors = ['field' => 'error message'];
        $exception = new BusinessValidationException('Validation failed', $errors);

        $response = $this->traitObject->validationErrorResponse($exception);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(422, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertTrue($data['error']);
        $this->assertEquals('validation_error', $data['type']);
        $this->assertEquals($errors, $data['errors']);
    }

    public function test_service_error_response_with_external_service_exception(): void
    {
        $exception = ExternalServiceException::rateLimited('api-service', 'https://api.test.com', 60);

        $response = $this->traitObject->serviceErrorResponse($exception);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(503, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertTrue($data['error']);
        $this->assertEquals('service_error', $data['type']);
        $this->assertEquals('api-service', $data['service']);
        $this->assertEquals(60, $data['retry_after']);
    }

    public function test_server_error_response(): void
    {
        $exception = new \Exception('Server error');

        $response = $this->traitObject->serverErrorResponse($exception);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(500, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertTrue($data['error']);
        $this->assertEquals('server_error', $data['type']);
    }
}
