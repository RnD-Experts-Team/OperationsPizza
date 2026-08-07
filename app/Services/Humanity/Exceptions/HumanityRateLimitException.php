<?php

namespace App\Services\Humanity\Exceptions;

/** Humanity application status 91 — "Throttle exceeded - max allowed requests". */
class HumanityRateLimitException extends HumanityException
{
    public function errorCode(): string
    {
        return 'HUMANITY_RATE_LIMITED';
    }

    public function retryAfterSeconds(): int
    {
        return (int) config('humanity.throttle_backoff_seconds', 30);
    }
}
