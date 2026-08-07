<?php

namespace App\Services\Scheduling\Dto;

use Carbon\CarbonImmutable;

/**
 * A shift's time, resolved once and used everywhere.
 *
 * The wall-clock trio is for display and for Humanity (which speaks local dates
 * and "HH:MM"); the UTC instants are for every query, sort and overlap check.
 */
final class ResolvedShiftTime
{
    public function __construct(
        public readonly string $shiftDate,      // Y-m-d, local
        public readonly string $startTime,      // H:i:s, local
        public readonly string $endTime,        // H:i:s, local
        public readonly CarbonImmutable $startsLocal,
        public readonly CarbonImmutable $endsLocal,
        public readonly CarbonImmutable $startsAtUtc,
        public readonly CarbonImmutable $endsAtUtc,
        public readonly int $durationMinutes,
        public readonly bool $crossesMidnight,
    ) {
    }

    public function toAttributes(): array
    {
        return [
            'shift_date' => $this->shiftDate,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'starts_at_utc' => $this->startsAtUtc->toDateTimeString(),
            'ends_at_utc' => $this->endsAtUtc->toDateTimeString(),
            'duration_minutes' => $this->durationMinutes,
            'crosses_midnight' => $this->crossesMidnight,
        ];
    }
}
