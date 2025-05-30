<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\DisableRateLimiting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

class DisableRateLimitingTest extends TestCase
{
    /**
     * Test that the middleware adds headers in development environment.
     */
    public function testAddsHeadersInDevelopmentEnvironment(): void
    {
        // Mock the application environment to be development
        App::shouldReceive('environment')
            ->once()
            ->with(['local', 'development', 'testing'])
            ->andReturn(true);

        $request = new Request();
        $middleware = new DisableRateLimiting();

        $response = $middleware->handle($request, function ($req) {
            // Verify the header was added to the request
            $this->assertTrue($req->headers->has('X-Rate-Limit-Disabled'));
            $this->assertEquals('true', $req->headers->get('X-Rate-Limit-Disabled'));

            return response()->json(['status' => 'success']);
        });

        // Verify the header was added to the response
        $this->assertTrue($response->headers->has('X-Rate-Limit-Disabled'));
        $this->assertEquals('true', $response->headers->get('X-Rate-Limit-Disabled'));
    }

    /**
     * Test that the middleware does nothing in production environment.
     */
    public function testDoesNothingInProductionEnvironment(): void
    {
        // Mock the application environment to be production
        App::shouldReceive('environment')
            ->once()
            ->with(['local', 'development', 'testing'])
            ->andReturn(false);

        $request = new Request();
        $middleware = new DisableRateLimiting();

        $response = $middleware->handle($request, function ($req) {
            // Verify the header was NOT added to the request
            $this->assertFalse($req->headers->has('X-Rate-Limit-Disabled'));

            return response()->json(['status' => 'success']);
        });

        // Verify the header was NOT added to the response
        $this->assertFalse($response->headers->has('X-Rate-Limit-Disabled'));
    }

    /**
     * Test the integration with a real HTTP request.
     */
    public function testIntegrationWithRealHttpRequest(): void
    {
        // Create a test route with the middleware
        $this->app['router']->get('test-disable-rate-limiting', function () {
            return response()->json(['status' => 'success']);
        })->middleware(DisableRateLimiting::class);

        // Make a request to the test route
        $response = $this->get('test-disable-rate-limiting');

        // Since we're in the testing environment, the middleware should add the header
        $response->assertHeader('X-Rate-Limit-Disabled', 'true');
        $response->assertJson(['status' => 'success']);
        $response->assertStatus(200);
    }
}
