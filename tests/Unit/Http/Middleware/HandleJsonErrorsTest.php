<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\HandleJsonErrors;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use JsonException;
use Tests\TestCase;
use Mockery;

class HandleJsonErrorsTest extends TestCase
{
    private HandleJsonErrors $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new HandleJsonErrors();
    }

    public function test_non_json_request_passes_through(): void
    {
        // Create a regular form request
        $request = new Request();
        $request->headers->set('Content-Type', 'application/x-www-form-urlencoded');

        $called = false;
        $next = function ($req) use (&$called) {
            $called = true;
            return response('next middleware called');
        };

        // Act
        $response = $this->middleware->handle($request, $next);

        // Assert
        $this->assertTrue($called, 'Next middleware was not called');
        $this->assertEquals('next middleware called', $response->getContent());
    }

    public function test_invalid_json_returns_error_response(): void
    {
        // Create a mock request that will throw a JsonException when json()->all() is called
        $jsonMock = Mockery::mock('stdClass');
        $jsonMock->shouldReceive('all')
            ->once()
            ->andThrow(new JsonException('Invalid JSON'));

        $request = Mockery::mock(Request::class);
        $request->shouldReceive('isJson')
            ->once()
            ->andReturn(true);
        $request->shouldReceive('wantsJson')
            ->andReturn(false); // Not needed since isJson returns true
        $request->shouldReceive('json')
            ->once()
            ->andReturn($jsonMock);

        $called = false;
        $next = function ($req) use (&$called) {
            $called = true;
            return response('next middleware called');
        };

        // Act
        $response = $this->middleware->handle($request, $next);

        // Assert
        $this->assertFalse($called, 'Next middleware should not have been called');
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(400, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Invalid JSON payload', $content['message']);
        $this->assertArrayHasKey('errors', $content);
        $this->assertArrayHasKey('json', $content['errors']);
    }

    public function test_valid_json_passes_through(): void
    {
        // Create a mock request that successfully returns from json()->all()
        $jsonMock = Mockery::mock('stdClass');
        $jsonMock->shouldReceive('all')
            ->once()
            ->andReturn(['test' => 'value']); // Return valid parsed JSON

        $request = Mockery::mock(Request::class);
        $request->shouldReceive('isJson')
            ->once()
            ->andReturn(true);
        $request->shouldReceive('wantsJson')
            ->andReturn(false); // Not needed since isJson returns true
        $request->shouldReceive('json')
            ->once()
            ->andReturn($jsonMock);

        $called = false;
        $next = function ($req) use (&$called) {
            $called = true;
            return response('next middleware called');
        };

        // Act
        $response = $this->middleware->handle($request, $next);

        // Assert
        $this->assertTrue($called, 'Next middleware was not called');
        $this->assertEquals('next middleware called', $response->getContent());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
