<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Support;

use DateTimeZone;

/**
 * Spreads a worked (or planned) interval over the store's local clock hours.
 *
 * The result is person-minutes per business date and hour, the same basis the
 * hourly sales table uses: the hour is the store-local hour of the day, and the
 * small hours after midnight belong to the PREVIOUS business date. The cutoff is
 * the first local hour of a new business day; a dinner rush ending at 01:00 is
 * still the day it started on.
 *
 * Pure: takes UTC instants and a timezone name, touches nothing else. Bucketing
 * on local-hour boundaries (not UTC ones) keeps the two DST days correct.
 */
final class HourlyCoverage
{
    /**
     * @param array<string, array<int, float>> $minutes  date => hour => person-minutes, added to in place
     */
    public static function addInterval(
        array &$minutes,
        int $startTs,
        int $endTs,
        string $timezone,
        int $cutoffHour = 5
    ): void {
        if ($endTs <= $startTs) {
            return;
        }

        $tz = new DateTimeZone($timezone);
        $cursor = $startTs;

        while ($cursor < $endTs) {
            $offset = $tz->getOffset((new \DateTimeImmutable('@' . $cursor)));
            $local = $cursor + $offset;

            // End of the local clock hour that contains $cursor.
            $hourEnd = (intdiv($local, 3600) + 1) * 3600 - $offset;
            $sliceEnd = min($hourEnd, $endTs);

            $hour = intdiv($local % 86400, 3600);
            $businessTs = $local - $cutoffHour * 3600;
            $date = gmdate('Y-m-d', $businessTs);

            $minutes[$date][$hour] = ($minutes[$date][$hour] ?? 0.0) + ($sliceEnd - $cursor) / 60;

            $cursor = $sliceEnd;
        }
    }
}
