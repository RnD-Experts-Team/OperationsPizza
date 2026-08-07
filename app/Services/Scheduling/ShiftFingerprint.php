<?php

namespace App\Services\Scheduling;

use App\Models\Shift;
use App\Services\Humanity\Dto\HumanityShiftResult;
use Carbon\CarbonImmutable;

/**
 * A stable hash of everything we mirror from Humanity, computed identically
 * from either side so the reconciler can skip untouched shifts without diffing
 * them field by field.
 *
 * Only mirrored fields go in. Our own bookkeeping (origin, created_by_user_id,
 * timestamps) must not, or every shift would look changed forever.
 */
class ShiftFingerprint
{
    public function forLocalShift(Shift $shift, array $humanityEmployeeIds): string
    {
        return $this->hash(
            (string) $shift->humanity_position_id,
            $shift->starts_at_utc?->toDateTimeString() ?? '',
            $shift->ends_at_utc?->toDateTimeString() ?? '',
            (string) $shift->note,
            (int) $shift->slots,
            (bool) $shift->is_published,
            $humanityEmployeeIds,
        );
    }

    public function forRemoteShift(HumanityShiftResult $shift, string $timezone): string
    {
        $starts = $this->utc($shift->startDate, $shift->startTime, $timezone);
        $ends = $this->utc($shift->endDate, $shift->endTime, $timezone);

        return $this->hash(
            (string) $shift->positionId,
            $starts,
            $ends,
            (string) $shift->note,
            $shift->slots,
            $shift->published,
            $shift->employeeIds,
        );
    }

    private function hash(
        string $positionId,
        string $startsAtUtc,
        string $endsAtUtc,
        string $note,
        int $slots,
        bool $published,
        array $employeeIds,
    ): string {
        // Employee order is not meaningful on either side, so sort before
        // hashing — otherwise a reordered response reads as a change.
        $employees = array_map('strval', $employeeIds);
        sort($employees);

        return sha1(implode('|', [
            $positionId,
            $startsAtUtc,
            $endsAtUtc,
            trim($note),
            $slots,
            $published ? '1' : '0',
            implode(',', $employees),
        ]));
    }

    private function utc(?string $date, ?string $time, string $timezone): string
    {
        if ($date === null || $time === null) {
            return '';
        }

        return CarbonImmutable::parse("{$date} {$time}", $timezone)->utc()->toDateTimeString();
    }
}
