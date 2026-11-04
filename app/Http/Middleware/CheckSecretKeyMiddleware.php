<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSecretKeyMiddleware
{
    /**
     * Handle an incoming request.
     *
     * Looks for "X-Secret-Key" in:
     *  - Request headers
     *  - Request body (JSON / form-data)
     *
     * Compares it to: config('services.x_secret_key')
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Read the secret from config/services.php (recommended) and .env (via env() inside config)
        $expected = (string) config('services.x_secret_key', '');

        // Fail closed if the server secret is not configured
        if ($expected === '') {
            return response()->json([
                'message' => 'Server secret key is not configured.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // 1) Check header first
        $provided = (string) $request->header('X-Secret-Key', '');

        // 2) If not present in header, check body (supports JSON, form-data, etc.)
        // Note: some clients may send x_secret_key instead of X-Secret-Key
        if ($provided === '') {
            $provided = (string) $request->input('X-Secret-Key', $request->input('x_secret_key', ''));
        }

        // Deny if missing or invalid (use constant-time compare)
        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
