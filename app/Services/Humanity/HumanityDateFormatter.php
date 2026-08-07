<?php

namespace App\Services\Humanity;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Humanity uses THREE different date/time formats, and mixing them up produces
 * silent wrong-day results rather than errors:
 *
 *   request dates      "Oct 14, 2022"   (human-readable, per the API docs)
 *   request times      "14:00"          (24-hour HH:MM)
 *   ?updated_at=       ISO8601          (positions/locations delta sync only)
 *   responses          Unix timestamps  (plus a pre-formatted object)
 *
 * All of them are interpreted in the ACCOUNT's timezone, not UTC — Humanity has
 * no per-request timezone parameter. Callers must therefore convert a UTC
 * instant into the store's local wall-clock time before formatting.
 */
class HumanityDateFormatter
{
    public const REQUEST_DATE = 'M j, Y';
    public const REQUEST_TIME = 'H:i';

    /** "Oct 14, 2022" */
    public function date(DateTimeInterface $date): string
    {
        return $date->format(self::REQUEST_DATE);
    }

    /** "14:00" */
    public function time(DateTimeInterface $time): string
    {
        return $time->format(self::REQUEST_TIME);
    }

    /** ISO8601, for the ?updated_at= delta parameter. */
    public function updatedAt(DateTimeInterface $moment): string
    {
        return CarbonImmutable::instance($moment)->utc()->toIso8601String();
    }

    /**
     * Parse whatever a response hands back. Humanity mostly returns Unix
     * timestamps, but some fields come through as formatted strings and some
     * as a nested object.
     */
    public function parse(mixed $value, ?string $timezone = null): ?CarbonImmutable
    {
        $tz = $timezone ?? 'UTC';

        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (is_array($value)) {
            // The pre-formatted object shape, e.g. {timestamp: ..., formatted: ...}
            foreach (['timestamp', 'time', 'formatted', 'date'] as $key) {
                if (isset($value[$key]) && !is_array($value[$key])) {
                    return $this->parse($value[$key], $timezone);
                }
            }

            return null;
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            // Unix timestamps are absolute, so the account timezone is
            // irrelevant here — only presentation needs $tz.
            return CarbonImmutable::createFromTimestampUTC((int) $value)->setTimezone($tz);
        }

        try {
            return CarbonImmutable::parse((string) $value, $tz);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Combine a local date and "HH:MM" into a zoned instant.
     * Used when reading a Humanity shift back into our mirror.
     */
    public function localDateTime(string $date, string $time, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::parse("{$date} {$time}", $timezone);
    }
}
