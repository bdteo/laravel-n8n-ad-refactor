<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\TestRateLimiting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\App;
use Tests\TestCase;
use Closure;
use Mockery;

class TestRateLimitingTest extends TestCase
{
    /**
     * @var TestRateLimiting
     */
    protected $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new TestRateLimiting();
    }

    public function test_skips_when_not_in_testing_environment(): void
    {
        // Mock the App facade
        App::shouldReceive('environment')
            ->once()
            ->with('testing')
            ->andReturn(false);

        $request = new Request();
        $response = response('OK');

        $next = function ($req) use ($response) {
            return $response;
        };

        $result = $this->middleware->handle($request, $next);

        $this->assertSame($response, $result);
        $this->assertFalse($result->headers->has('X-RateLimit-Limit'));
    }

    public function test_applies_headers_when_rate_limiting_disabled(): void
    {
        // Bind the testing.rate_limiting value as false
        $this->app->instance('testing.rate_limiting', false);

        $request = new Request();
        $response = $this->middleware->handle($request, function ($req) {
            return response('OK');
        });

        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertEquals('1000', $response->headers->get('X-RateLimit-Limit'));
        $this->assertEquals('999', $response->headers->get('X-RateLimit-Remaining'));
        $this->assertEquals('true', $response->headers->get('X-Rate-Limit-Bypassed'));
    }

    public function test_bypasses_with_throttle_middleware_disabled_attribute(): void
    {
        // Bind the testing.rate_limiting value as true
        $this->app->instance('testing.rate_limiting', true);

        $request = new Request();
        $request->attributes->set('throttle_middleware_disabled', true);

        $response = $this->middleware->handle($request, function ($req) {
            return response('OK');
        });

        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertEquals('1000', $response->headers->get('X-RateLimit-Limit'));
        $this->assertEquals('999', $response->headers->get('X-RateLimit-Remaining'));
        $this->assertEquals('true', $response->headers->get('X-Rate-Limit-Bypassed'));
    }

    public function test_bypasses_with_disable_rate_limiting_header(): void
    {
        // Bind the testing.rate_limiting value as true
        $this->app->instance('testing.rate_limiting', true);

        $request = new Request();
        $request->headers->set('X-Disable-Rate-Limiting', 'true');

        $response = $this->middleware->handle($request, function ($req) {
            return response('OK');
        });

        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertEquals('1000', $response->headers->get('X-RateLimit-Limit'));
        $this->assertEquals('999', $response->headers->get('X-RateLimit-Remaining'));
        $this->assertEquals('true', $response->headers->get('X-Rate-Limit-Bypassed'));
    }

    public function test_applies_rate_limiting_for_ad_script_submission(): void
    {
        // Bind the testing.rate_limiting value as true
        $this->app->instance('testing.rate_limiting', true);

        // Create a request with a path that triggers ad-script-submission rate limiting
        $request = Request::create('/api/ad-scripts', 'POST');
        $request->headers->set('X-Enable-Rate-Limiting', 'true');

        // Mock the RateLimiter facade
        RateLimiter::shouldReceive('attempts')
            ->once()
            ->with('ad-script-submission:127.0.0.1')
            ->andReturn(5);

        RateLimiter::shouldReceive('hit')
            ->once()
            ->with('ad-script-submission:127.0.0.1', 60)
            ->andReturn(6);

        $response = $this->middleware->handle($request, function ($req) {
            return response('OK');
        });

        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertEquals('10', $response->headers->get('X-RateLimit-Limit'));
        $this->assertEquals('4', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function test_applies_rate_limiting_for_result_processing(): void
    {
        // Bind the testing.rate_limiting value as true
        $this->app->instance('testing.rate_limiting', true);

        // Create a request with a path that triggers result-processing rate limiting
        $request = Request::create('/api/ad-scripts/abc-123/result', 'POST');
        $request->headers->set('X-Enable-Rate-Limiting', 'true');

        // Mock the RateLimiter facade
        RateLimiter::shouldReceive('attempts')
            ->once()
            ->with('result-processing:127.0.0.1')
            ->andReturn(10);

        RateLimiter::shouldReceive('hit')
            ->once()
            ->with('result-processing:127.0.0.1', 60)
            ->andReturn(11);

        $response = $this->middleware->handle($request, function ($req) {
            return response('OK');
        });

        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertEquals('30', $response->headers->get('X-RateLimit-Limit'));
        $this->assertEquals('19', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function test_returns_429_when_rate_limit_exceeded(): void
    {
        // Bind the testing.rate_limiting value as true
        $this->app->instance('testing.rate_limiting', true);

        // Create a request with a path that triggers ad-script-submission rate limiting
        $request = Request::create('/api/ad-scripts', 'POST');
        $request->headers->set('X-Enable-Rate-Limiting', 'true');

        // Mock the RateLimiter facade to return a count that exceeds the limit
        RateLimiter::shouldReceive('attempts')
            ->once()
            ->with('ad-script-submission:127.0.0.1')
            ->andReturn(10); // limit is 10, so this should trigger rate limiting

        $response = $this->middleware->handle($request, function ($req) {
            return response('OK');
        });

        $this->assertEquals(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertEquals('10', $response->headers->get('X-RateLimit-Limit'));
        $this->assertEquals('0', $response->headers->get('X-RateLimit-Remaining'));
        $this->assertEquals('60', $response->headers->get('Retry-After'));

        // Check the JSON response
        $content = json_decode($response->getContent(), true);
        $this->assertTrue($content['error']);
        $this->assertEquals('Too Many Requests', $content['message']);
        $this->assertEquals('rate_limit_error', $content['type']);
        $this->assertArrayHasKey('request_id', $content);
        $this->assertArrayHasKey('timestamp', $content);
    }

    public function test_default_processing_with_no_special_headers(): void
    {
        // Bind the testing.rate_limiting value as true
        $this->app->instance('testing.rate_limiting', true);

        // Create a request with a path that doesn't match any rate limiting conditions
        $request = Request::create('/api/some-other-endpoint', 'GET');

        $response = $this->middleware->handle($request, function ($req) {
            return response('OK');
        });

        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertEquals('1000', $response->headers->get('X-RateLimit-Limit'));
        $this->assertEquals('999', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function test_handles_unknown_path_with_rate_limiting_enabled(): void
    {
        // Bind the testing.rate_limiting value as true
        $this->app->instance('testing.rate_limiting', true);

        // Create a request with a path that doesn't match any rate limiting conditions
        $request = Request::create('/api/some-other-endpoint', 'GET');
        $request->headers->set('X-Enable-Rate-Limiting', 'true');

        $response = $this->middleware->handle($request, function ($req) {
            return response('OK');
        });

        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertEquals('1000', $response->headers->get('X-RateLimit-Limit'));
        $this->assertEquals('999', $response->headers->get('X-RateLimit-Remaining'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
