<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\RedirectIfAuthenticated;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class RedirectIfAuthenticatedTest extends TestCase
{
    public function test_guest_user_passes_through(): void
    {
        // Arrange
        $middleware = new RedirectIfAuthenticated();
        $request = new Request();

        $called = false;
        $next = function ($req) use (&$called) {
            $called = true;

            return response('next middleware called');
        };

        // Mock Auth to return false for check
        Auth::shouldReceive('guard')
            ->once()
            ->with(null)
            ->andReturnSelf();

        Auth::shouldReceive('check')
            ->once()
            ->andReturn(false);

        // Act
        $response = $middleware->handle($request, $next);

        // Assert
        $this->assertTrue($called, 'Next middleware was not called');
        $this->assertEquals('next middleware called', $response->getContent());
    }

    public function test_authenticated_user_is_redirected(): void
    {
        // Arrange
        $middleware = new RedirectIfAuthenticated();
        $request = new Request();

        $called = false;
        $next = function ($req) use (&$called) {
            $called = true;

            return response('next middleware called');
        };

        // Mock Auth to return true for check
        Auth::shouldReceive('guard')
            ->once()
            ->with(null)
            ->andReturnSelf();

        Auth::shouldReceive('check')
            ->once()
            ->andReturn(true);

        // Act
        $response = $middleware->handle($request, $next);

        // Assert
        $this->assertFalse($called, 'Next middleware should not have been called');
        $this->assertInstanceOf(\Illuminate\Http\RedirectResponse::class, $response);
        $this->assertStringEndsWith(RouteServiceProvider::HOME, $response->getTargetUrl());
    }

    public function test_middleware_supports_multiple_guards(): void
    {
        // Arrange
        $middleware = new RedirectIfAuthenticated();
        $request = new Request();

        $called = false;
        $next = function ($req) use (&$called) {
            $called = true;

            return response('next middleware called');
        };

        // Mock Auth to return false for both guards
        Auth::shouldReceive('guard')
            ->once()
            ->with('web')
            ->andReturnSelf();

        Auth::shouldReceive('check')
            ->once()
            ->andReturn(false);

        Auth::shouldReceive('guard')
            ->once()
            ->with('api')
            ->andReturnSelf();

        Auth::shouldReceive('check')
            ->once()
            ->andReturn(false);

        // Act
        $response = $middleware->handle($request, $next, 'web', 'api');

        // Assert
        $this->assertTrue($called, 'Next middleware was not called');
        $this->assertEquals('next middleware called', $response->getContent());
    }

    public function test_middleware_redirects_if_any_guard_authenticated(): void
    {
        // Arrange
        $middleware = new RedirectIfAuthenticated();
        $request = new Request();

        $called = false;
        $next = function ($req) use (&$called) {
            $called = true;

            return response('next middleware called');
        };

        // Mock Auth to return false for first guard but true for second
        Auth::shouldReceive('guard')
            ->once()
            ->with('web')
            ->andReturnSelf();

        Auth::shouldReceive('check')
            ->once()
            ->andReturn(false);

        Auth::shouldReceive('guard')
            ->once()
            ->with('api')
            ->andReturnSelf();

        Auth::shouldReceive('check')
            ->once()
            ->andReturn(true);

        // Act
        $response = $middleware->handle($request, $next, 'web', 'api');

        // Assert
        $this->assertFalse($called, 'Next middleware should not have been called');
        $this->assertInstanceOf(\Illuminate\Http\RedirectResponse::class, $response);
        $this->assertStringEndsWith(RouteServiceProvider::HOME, $response->getTargetUrl());
    }
}
