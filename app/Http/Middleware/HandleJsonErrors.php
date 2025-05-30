<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use JsonException;

class HandleJsonErrors
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->isJson() || $request->wantsJson()) {
            try {
                // This will trigger JSON parsing and throw JsonException if invalid
                $request->json()->all();
            } catch (JsonException $e) {
                return response()->json([
                    'message' => 'Invalid JSON payload',
                    'errors' => [
                        'json' => ['The request contains invalid JSON.'],
                    ],
                ], 400);
            }
        }

        return $next($request);
    }
}
