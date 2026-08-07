<?php

namespace App\Services\Scheduling;

use App\Models\StoreScheduleSetting;
use Carbon\CarbonImmutable;

/**
 * The business week starts on TUESDAY, not Sunday or Monday — the scheduling
 * grid's dayIndex 0 is Tuesday (see getWeekDates() in the frontend).
 *
 * Nothing here is hardcoded to Tuesday though: week_start_dow lives on
 * store_schedule_settings, so a store on a different week can be configured
 * without touching code. Shifts always store an absolute date; day_index is
 * derived per request and never persisted (the one exception is
 * schedule_template_shifts, which is week-relative by definition).
 */
class WeekResolver
{
    public const DEFAULT_WEEK_START_DOW = 2; // Tuesday

    public function weekStartDow(?StoreScheduleSetting $settings): int
    {
        $dow = $settings?->week_start_dow;

        return $dow === null ? self::DEFAULT_WEEK_START_DOW : (int) $dow;
    }

    /** The start of the week containing $date. */
    public function weekStartFor(CarbonImmutable $date, int $weekStartDow): CarbonImmutable
    {
        $offset = ($date->dayOfWeek - $weekStartDow + 7) % 7;

        return $date->startOfDay()->subDays($offset);
    }

    /**
     * Snap an arbitrary date to its week start. Callers pass a week_start from
     * the client, which may be a day the user picked rather than a true
     * boundary — silently correcting beats returning a week offset by a day.
     */
    public function normalizeWeekStart(string $date, int $weekStartDow): CarbonImmutable
    {
        return $this->weekStartFor(CarbonImmutable::parse($date), $weekStartDow);
    }

    /** 0..6, or null when the date falls outside the week. */
    public function dayIndexFor(CarbonImmutable $date, CarbonImmutable $weekStart): ?int
    {
        $index = $weekStart->startOfDay()->diffInDays($date->startOfDay(), false);

        return ($index >= 0 && $index <= 6) ? (int) $index : null;
    }

    /** @return array<int, CarbonImmutable> The seven dates, in grid order. */
    public function datesForWeek(CarbonImmutable $weekStart): array
    {
        return array_map(fn (int $i) => $weekStart->addDays($i), range(0, 6));
    }

    /** "Aug 4 – Aug 10, 2026", matching the label the grid already renders. */
    public function label(CarbonImmutable $weekStart): string
    {
        $end = $weekStart->addDays(6);

        return sprintf(
            '%s – %s, %s',
            $weekStart->format('M j'),
            $end->format('M j'),
            $end->format('Y')
        );
    }
}
