<?php

namespace App\Services\Tcp\Exceptions;

/**
 * HTTP 429, or our own throttle refusing before we get there.
 *
 * TCP allows 60/minute AND 2500 per rolling 24h. The daily cap is the tight
 * one — roughly 104/hour for the entire service — so this is an expected
 * operating condition to be scheduled around, not a rare error.
 */
class TcpRateLimitException extends TcpException
{
    public function __construct(
        string $message,
        public readonly int $retryAfterSeconds = 60,
        public readonly bool $isDailyCap = false,
        ?int $httpStatus = null,
    ) {
        parent::__construct($message, $httpStatus);
    }

    public function errorCode(): string
    {
        return $this->isDailyCap ? 'TCP_DAILY_QUOTA_EXHAUSTED' : 'TCP_RATE_LIMITED';
    }
}
