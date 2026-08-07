<?php

namespace App\Exceptions\Renderers;

use App\Services\Humanity\Exceptions\HumanityException;
use App\Services\Humanity\Exceptions\HumanityRateLimitException;
use App\Services\Scheduling\Exceptions\InvalidLocalTimeException;
use App\Services\Scheduling\Exceptions\SchedulingException;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Domain failures become structured errors the UI can act on, rather than a
 * generic 500. The `error.code` is the contract — the frontend branches on it
 * (EMPLOYEE_NOT_SYNCED opens the sync-and-retry flow, SHIFT_CONFLICT offers
 * "schedule anyway").
 */
class SchedulingExceptionRenderer
{
    public function render(Throwable $e): ?JsonResponse
    {
        if ($e instanceof SchedulingException) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => ['code' => $e->errorCode] + $e->context,
            ], $e->statusCode);
        }

        if ($e instanceof InvalidLocalTimeException) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => [
                    'code' => 'INVALID_LOCAL_TIME',
                    'date' => $e->date,
                    'time' => $e->time,
                    'timezone' => $e->timezone,
                ],
            ], 422);
        }

        if ($e instanceof HumanityRateLimitException) {
            return response()->json([
                'message' => 'Humanity is rate limiting us. Please retry shortly.',
                'error' => ['code' => $e->errorCode(), 'retry_after_seconds' => $e->retryAfterSeconds()],
            ], 503, ['Retry-After' => (string) $e->retryAfterSeconds()]);
        }

        if ($e instanceof HumanityException) {
            // 502, not 500: the failure is upstream, and nothing was persisted
            // here — the write-through ordering guarantees that.
            return response()->json([
                'message' => 'The scheduling system (Humanity) rejected this change.',
                'error' => [
                    'code' => 'HUMANITY_WRITE_FAILED',
                    'humanity_status' => $e->humanityStatus,
                    'detail' => $e->getMessage(),
                ],
            ], 502);
        }

        return null;
    }
}
