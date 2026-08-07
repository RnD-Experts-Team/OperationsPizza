<?php

namespace App\Services\Scheduling;

use App\Services\Scheduling\Dto\ResolvedShiftTime;
use App\Services\Scheduling\Exceptions\InvalidLocalTimeException;
use Carbon\CarbonImmutable;

/**
 * Turns a local date + start/end wall times into both representations we store.
 *
 * Two things make this less trivial than it looks:
 *
 * 1. Shifts crossing midnight are ROUTINE here — the store closes at 00:00, so
 *    end_time <= start_time is the normal case, not an edge case. The end
 *    instant is therefore always computed by adding the duration to the start,
 *    never by parsing end_time against shift_date.
 *
 * 2. DST. A spring-forward wall time simply does not exist, and PHP silently
 *    rolls it forward rather than complaining; we round-trip and reject instead.
 *    On fall-back, duration_minutes is the true UTC delta, so a 22:00→06:00
 *    shift that night is 9h, not 8h. That is the payroll-facing number, and it
 *    is why nothing should ever recompute hours from the wall-clock strings.
 */
class ShiftTimeResolver
{
    public function resolve(string $date, string $startTime, string $endTime, string $timezone): ResolvedShiftTime
    {
        $startTime = $this->normalizeTime($startTime);
        $endTime = $this->normalizeTime($endTime);

        $startsLocal = $this->localInstant($date, $startTime, $timezone);
        $crossesMidnight = $this->crossesMidnight($startTime, $endTime);

        // The end is resolved as a WALL CLOCK time on its own date, exactly
        // like the start — not by adding the on-the-clock duration to the
        // start. Adding minutes to a zoned instant adds absolute time, which
        // would silently paper over both DST transitions: "22:00 to 06:00"
        // means "until the clock reads 06:00", and on the fall-back night that
        // is nine real hours, not eight.
        $endDate = $crossesMidnight
            ? $startsLocal->addDay()->format('Y-m-d')
            : $startsLocal->format('Y-m-d');

        // Unlike the start, a non-existent end time is tolerated: PHP rolls it
        // forward to the same instant the skipped hour would have been, which
        // is the right answer for "when does this shift finish".
        $endsLocal = CarbonImmutable::parse("{$endDate} {$endTime}", $timezone);

        $startsAtUtc = $startsLocal->utc();
        $endsAtUtc = $endsLocal->utc();

        // The real elapsed time, which differs from the wall-clock duration on
        // the two DST days a year. This is the payroll-facing number.
        $actualMinutes = (int) round(($endsAtUtc->getTimestamp() - $startsAtUtc->getTimestamp()) / 60);

        return new ResolvedShiftTime(
            shiftDate: $startsLocal->format('Y-m-d'),
            startTime: $startsLocal->format('H:i:s'),
            endTime: $endsLocal->format('H:i:s'),
            startsLocal: $startsLocal,
            endsLocal: $endsLocal,
            startsAtUtc: $startsAtUtc,
            endsAtUtc: $endsAtUtc,
            durationMinutes: $actualMinutes,
            crossesMidnight: $crossesMidnight,
        );
    }

    /**
     * Build a zoned instant and assert the wall clock survived the round trip.
     * If it didn't, the time doesn't exist (spring forward) — PHP would quietly
     * shift it an hour and produce a shift nobody asked for.
     */
    public function localInstant(string $date, string $time, string $timezone): CarbonImmutable
    {
        $time = $this->normalizeTime($time);
        $instant = CarbonImmutable::parse("{$date} {$time}", $timezone);

        if ($instant->format('H:i') !== substr($time, 0, 5)) {
            throw new InvalidLocalTimeException($date, $time, $timezone);
        }

        return $instant;
    }

    /**
     * Minutes on the clock face. "22:00"→"02:00" is 240; "09:00"→"00:00" is 900
     * (midnight is the END of the day here, never a zero-length shift).
     */
    public function wallClockDurationMinutes(string $startTime, string $endTime): int
    {
        $start = $this->minutesOfDay($startTime);
        $end = $this->minutesOfDay($endTime);

        if ($end <= $start) {
            $end += 24 * 60;
        }

        return $end - $start;
    }

    public function crossesMidnight(string $startTime, string $endTime): bool
    {
        return $this->minutesOfDay($endTime) <= $this->minutesOfDay($startTime);
    }

    private function minutesOfDay(string $time): int
    {
        [$hours, $minutes] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

        return $hours * 60 + $minutes;
    }

    /** Accepts "9:00", "09:00" and "09:00:00". */
    private function normalizeTime(string $time): string
    {
        $parts = array_map('intval', explode(':', trim($time)));

        return sprintf('%02d:%02d:%02d', $parts[0] ?? 0, $parts[1] ?? 0, $parts[2] ?? 0);
    }
}
