<?php

namespace App\Services\Scheduling\Exceptions;

use RuntimeException;

/**
 * The requested wall-clock time does not exist in the store's timezone —
 * i.e. it falls in the hour skipped by a DST spring-forward.
 */
class InvalidLocalTimeException extends RuntimeException
{
    public function __construct(
        public readonly string $date,
        public readonly string $time,
        public readonly string $timezone,
    ) {
        parent::__construct(
            "{$date} {$time} does not exist in {$timezone} (daylight saving transition)."
        );
    }
}
