<?php

namespace App\Services\Scheduling\Exceptions;

use RuntimeException;

/**
 * A domain failure the API can render as a structured error the UI can act on.
 * `context` becomes the `error` object in the response body.
 */
class SchedulingException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $statusCode = 409,
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function storeNotMapped(string $storeNumber): self
    {
        return new self(
            "Store {$storeNumber} is not mapped to a Humanity location yet.",
            'STORE_NOT_MAPPED',
            422,
            ['store_number' => $storeNumber],
        );
    }

    public static function positionNotMapped(string $storeNumber, ?int $positionId): self
    {
        return new self(
            'No Humanity position is mapped for this shift, and the store has no default.',
            'POSITION_NOT_MAPPED',
            422,
            ['store_number' => $storeNumber, 'position_id' => $positionId],
        );
    }

    public static function outsideStoreHours(string $open, string $close): self
    {
        return new self(
            "Shift falls outside store hours ({$open}–{$close}).",
            'OUTSIDE_STORE_HOURS',
            422,
            ['open_time' => $open, 'close_time' => $close],
        );
    }

    public static function conflict(array $conflicts): self
    {
        return new self(
            'This employee already has an overlapping shift.',
            'SHIFT_CONFLICT',
            409,
            ['conflicts' => $conflicts],
        );
    }

    public static function unavailable(array $rules): self
    {
        return new self(
            'This employee is not available at that time.',
            'EMPLOYEE_UNAVAILABLE',
            409,
            ['rules' => $rules],
        );
    }

    public static function onTimeOff(array $entries): self
    {
        return new self(
            'This employee has approved time off then.',
            'EMPLOYEE_ON_TIME_OFF',
            409,
            ['time_off' => $entries],
        );
    }
}
