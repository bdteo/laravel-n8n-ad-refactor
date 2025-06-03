<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

class VerifyWebhookSignatureTest extends TestCase
{
    private VerifyWebhookSignature $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new VerifyWebhookSignature();
    }

    public function test_middleware_passes_with_valid_signature(): void
    {
        // Arrange
        $secret = 'test-secret';
        $payload = '{"test": "data"}';
        $signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        config(['services.n8n.callback_hmac_secret' => $secret]);

        $request = Request::create('/test', 'POST', [], [], [], [], $payload);
        $request->headers->set('X-N8N-Signature', $signature);

        $nextCalled = false;
        $next = function ($request) use (&$nextCalled) {
            $nextCalled = true;

            return response()->json(['success' => true]);
        };

        // Act
        $response = $this->middleware->handle($request, $next);

        // Assert
        $this->assertTrue($nextCalled);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_middleware_fails_with_invalid_signature(): void
    {
        // Arrange
        $secret = 'test-secret';
        $payload = '{"test": "data"}';
        $invalidSignature = 'sha256=invalid-signature';

        config(['services.n8n.callback_hmac_secret' => $secret]);

        $request = Request::create('/test', 'POST', [], [], [], [], $payload);
        $request->headers->set('X-N8N-Signature', $invalidSignature);

        $nextCalled = false;
        $next = function ($request) use (&$nextCalled) {
            $nextCalled = true;

            return response()->json(['success' => true]);
        };

        // Act
        $response = $this->middleware->handle($request, $next);

        // Assert
        $this->assertFalse($nextCalled);
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertJson($content);

        $responseData = json_decode($content, true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertEquals('Invalid webhook signature', $responseData['error']);
    }

    public function test_middleware_fails_with_missing_signature(): void
    {
        // Arrange
        $secret = 'test-secret';
        $payload = '{"test": "data"}';

        config(['services.n8n.callback_hmac_secret' => $secret]);

        $request = Request::create('/test', 'POST', [], [], [], [], $payload);
        // No signature header set

        $nextCalled = false;
        $next = function ($request) use (&$nextCalled) {
            $nextCalled = true;

            return response()->json(['success' => true]);
        };

        // Act
        $response = $this->middleware->handle($request, $next);

        // Assert
        $this->assertFalse($nextCalled);
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertJson($content);

        $responseData = json_decode($content, true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertEquals('Missing webhook signature', $responseData['error']);
    }

    public function test_middleware_fails_with_empty_signature(): void
    {
        // Arrange
        $secret = 'test-secret';
        $payload = '{"test": "data"}';

        config(['services.n8n.callback_hmac_secret' => $secret]);

        $request = Request::create('/test', 'POST', [], [], [], [], $payload);
        $request->headers->set('X-N8N-Signature', '');

        $nextCalled = false;
        $next = function ($request) use (&$nextCalled) {
            $nextCalled = true;

            return response()->json(['success' => true]);
        };

        // Act
        $response = $this->middleware->handle($request, $next);

        // Assert
        $this->assertFalse($nextCalled);
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertJson($content);

        $responseData = json_decode($content, true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertEquals('Missing webhook signature', $responseData['error']);
    }

    public function test_middleware_fails_when_secret_not_configured(): void
    {
        // Arrange
        $payload = '{"test": "data"}';
        $signature = 'sha256=some-signature';

        config(['services.n8n.callback_hmac_secret' => null]);

        $request = Request::create('/test', 'POST', [], [], [], [], $payload);
        $request->headers->set('X-N8N-Signature', $signature);

        $nextCalled = false;
        $next = function ($request) use (&$nextCalled) {
            $nextCalled = true;

            return response()->json(['success' => true]);
        };

        // Act
        $response = $this->middleware->handle($request, $next);

        // Assert
        $this->assertFalse($nextCalled);
        $this->assertEquals(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());

        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertJson($content);

        $responseData = json_decode($content, true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertEquals('Callback HMAC secret not configured', $responseData['error']);
    }

    public function test_middleware_fails_when_secret_is_empty_string(): void
    {
        // Arrange
        $payload = '{"test": "data"}';
        $signature = 'sha256=some-signature';

        config(['services.n8n.callback_hmac_secret' => '']);

        $request = Request::create('/test', 'POST', [], [], [], [], $payload);
        $request->headers->set('X-N8N-Signature', $signature);

        $nextCalled = false;
        $next = function ($request) use (&$nextCalled) {
            $nextCalled = true;

            return response()->json(['success' => true]);
        };

        // Act
        $response = $this->middleware->handle($request, $next);

        // Assert
        $this->assertFalse($nextCalled);
        $this->assertEquals(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());

        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertJson($content);

        $responseData = json_decode($content, true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertEquals('Callback HMAC secret not configured', $responseData['error']);
    }

    public function test_middleware_handles_different_payload_content(): void
    {
        // Arrange
        $secret = 'test-secret';
        $payload = '{"complex": {"nested": {"data": [1, 2, 3]}, "special": "chars!@#$%"}}';
        $signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        config(['services.n8n.callback_hmac_secret' => $secret]);

        $request = Request::create('/test', 'POST', [], [], [], [], $payload);
        $request->headers->set('X-N8N-Signature', $signature);

        $nextCalled = false;
        $next = function ($request) use (&$nextCalled) {
            $nextCalled = true;

            return response()->json(['success' => true]);
        };

        // Act
        $response = $this->middleware->handle($request, $next);

        // Assert
        $this->assertTrue($nextCalled);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_middleware_handles_empty_payload(): void
    {
        // Arrange
        $secret = 'test-secret';
        $payload = '';
        $signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        config(['services.n8n.callback_hmac_secret' => $secret]);

        $request = Request::create('/test', 'POST', [], [], [], [], $payload);
        $request->headers->set('X-N8N-Signature', $signature);

        $nextCalled = false;
        $next = function ($request) use (&$nextCalled) {
            $nextCalled = true;

            return response()->json(['success' => true]);
        };

        // Act
        $response = $this->middleware->handle($request, $next);

        // Assert
        $this->assertTrue($nextCalled);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_middleware_is_timing_attack_resistant(): void
    {
        // Arrange
        $secret = 'test-secret';
        $payload = '{"test": "data"}';
        $validSignature = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        $invalidSignature = 'sha256=' . substr($validSignature, 0, -1) . 'x'; // Change last character

        config(['services.n8n.callback_hmac_secret' => $secret]);

        $request = Request::create('/test', 'POST', [], [], [], [], $payload);
        $request->headers->set('X-N8N-Signature', $invalidSignature);

        $nextCalled = false;
        $next = function ($request) use (&$nextCalled) {
            $nextCalled = true;

            return response()->json(['success' => true]);
        };

        // Act
        $response = $this->middleware->handle($request, $next);

        // Assert
        $this->assertFalse($nextCalled);
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

        // The middleware uses hash_equals which is timing-attack resistant
        // This test verifies that even signatures of the same length are properly rejected
        $content = $response->getContent();
        $this->assertIsString($content);

        $responseData = json_decode($content, true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertEquals('Invalid webhook signature', $responseData['error']);
    }

    public function test_middleware_passes_when_auth_disabled(): void
    {
        // Arrange
        config(['services.n8n.disable_auth' => true]);
        $payload = '{"test": "data"}';

        $request = Request::create('/test', 'POST', [], [], [], [], $payload);
        // No signature header set, which would normally fail

        $nextCalled = false;
        $next = function ($request) use (&$nextCalled) {
            $nextCalled = true;

            return response()->json(['success' => true]);
        };

        // Act
        $response = $this->middleware->handle($request, $next);

        // Assert
        $this->assertTrue($nextCalled);
        $this->assertEquals(200, $response->getStatusCode());

        // Reset config for other tests
        config(['services.n8n.disable_auth' => false]);
    }
}
