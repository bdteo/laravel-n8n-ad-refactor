<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class VerifyWebhookSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $secret = config('services.n8n.callback_hmac_secret');

        if (empty($secret) || ! is_string($secret)) {
            return response()->json([
                'error' => 'Callback HMAC secret not configured',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $signature = $request->header('X-N8N-Signature');

        if (empty($signature)) {
            return response()->json([
                'error' => 'Missing webhook signature',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $payload = $request->getContent();
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        if (! hash_equals($expectedSignature, $signature)) {
            return response()->json([
                'error' => 'Invalid webhook signature',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
