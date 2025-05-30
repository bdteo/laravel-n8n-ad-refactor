<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\NoThrottleRequests;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class NoThrottleRequestsTest extends TestCase
{
    protected RateLimiter $limiter;
    protected NoThrottleRequests $middleware;
    protected Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->limiter = $this->app->make(RateLimiter::class);
        $this->middleware = new NoThrottleRequests($this->limiter);
        $this->router = $this->app->make(Router::class);

        // Reset the config for each test
        Config::set('services.n8n.disable_rate_limiting', false);
    }

    /**
     * Create a request with a route to avoid the signature generation error.
     */
    protected function createRequestWithRoute(array $headers = []): Request
    {
        $request = Request::create('/test-path', 'GET');

        foreach ($headers as $key => $value) {
            $request->headers->set($key, $value);
        }

        // Add a route to the request to avoid "Unable to generate the request signature" error
        $route = $this->router->get('/test-path', function () {
            return 'test';
        });

        $request->setRouteResolver(function () use ($route) {
            return $route;
        });

        return $request;
    }

    /**
     * Test that rate limiting is bypassed when header is set.
     */
    public function testBypassesRateLimitingWhenHeaderIsSet(): void
    {
        $request = $this->createRequestWithRoute([
            'X-Disable-Rate-Limiting' => 'true'
        ]);

        $response = $this->middleware->handle($request, function ($req) {
            return response()->json(['status' => 'success']);
        }, 60, 1);

        // Check response headers indicating bypass
        $this->assertTrue($response->headers->has('X-RateLimit-Bypassed'));
        $this->assertEquals('true', $response->headers->get('X-RateLimit-Bypassed'));

        // Mock headers should still be included
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
    }

    /**
     * Test that rate limiting is applied when no bypass is requested.
     */
    public function testAppliesRateLimitingNormally(): void
    {
        $request = $this->createRequestWithRoute();

        $response = $this->middleware->handle($request, function ($req) {
            return response()->json(['status' => 'success']);
        }, 60, 1);

        // Check normal rate limit headers are present
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));

        // Should not have bypass header
        $this->assertFalse($response->headers->has('X-RateLimit-Bypassed'));
    }

    /**
     * Test that rate limiting can be bypassed via config.
     */
    public function testBypassesRateLimitingFromConfig(): void
    {
        // Configure to bypass rate limiting
        Config::set('services.n8n.disable_rate_limiting', true);

        $request = $this->createRequestWithRoute();

        $response = $this->middleware->handle($request, function ($req) {
            return response()->json(['status' => 'success']);
        }, 60, 1);

        // Check response headers indicating bypass
        $this->assertTrue($response->headers->has('X-RateLimit-Bypassed'));
        $this->assertEquals('true', $response->headers->get('X-RateLimit-Bypassed'));
    }

    /**
     * Test that an explicit header to enable rate limiting overrides config.
     */
    public function testHeaderOverridesConfig(): void
    {
        // Configure to bypass rate limiting
        Config::set('services.n8n.disable_rate_limiting', true);

        // But explicitly request rate limiting
        $request = $this->createRequestWithRoute([
            'X-Enable-Rate-Limiting' => 'true'
        ]);

        $response = $this->middleware->handle($request, function ($req) {
            return response()->json(['status' => 'success']);
        }, 60, 1);

        // Should not have bypass header
        $this->assertFalse($response->headers->has('X-RateLimit-Bypassed'));

        // Normal rate limit headers should be present
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
    }

    /**
     * Test that middleware can be used in testing environment with special header.
     */
    public function testSpecialHandlingForTestingEnvironment(): void
    {
        // Set environment to testing
        $this->app['env'] = 'testing';

        $request = $this->createRequestWithRoute([
            'X-Testing-Rate-Limits' => 'true'
        ]);

        $response = $this->middleware->handle($request, function ($req) {
            return response()->json(['status' => 'success']);
        }, 60, 1);

        // Should have normal rate limit headers
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));

        // Should not have bypass header
        $this->assertFalse($response->headers->has('X-RateLimit-Bypassed'));
    }

    /**
     * Test that request attribute can disable throttling.
     */
    public function testRequestAttributeCanDisableThrottling(): void
    {
        $request = $this->createRequestWithRoute();
        $request->attributes->set('throttle_middleware_disabled', true);

        $response = $this->middleware->handle($request, function ($req) {
            return response()->json(['status' => 'success']);
        }, 60, 1);

        // Check response headers indicating bypass
        $this->assertTrue($response->headers->has('X-RateLimit-Bypassed'));
        $this->assertEquals('true', $response->headers->get('X-RateLimit-Bypassed'));
    }

    /**
     * Test integration with HTTP request.
     */
    public function testIntegrationWithHttpRequest(): void
    {
        // Create a test route with the middleware
        $this->app['router']->get('test-throttle', function () {
            return response()->json(['status' => 'success']);
        })->middleware(NoThrottleRequests::class . ':60,1');

        // Make request with bypass header
        $response = $this->get('test-throttle', [
            'X-Disable-Rate-Limiting' => 'true'
        ]);

        $response->assertStatus(200);
        $response->assertHeader('X-RateLimit-Bypassed', 'true');
        $response->assertHeader('X-RateLimit-Limit', '60');
    }
}
