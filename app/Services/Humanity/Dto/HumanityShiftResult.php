<?php

namespace App\Services\Humanity\Dto;

/**
 * A Humanity shift as it now stands, normalised out of the raw response.
 * The reconciler compares these against our mirror, so it needs the same
 * fields in both directions.
 */
final class HumanityShiftResult
{
    /**
     * @param  array<int, string>  $employeeIds
     */
    public function __construct(
        public readonly string $shiftId,
        public readonly ?string $positionId,
        public readonly ?string $locationId,
        public readonly ?string $startDate,   // Y-m-d, store-local
        public readonly ?string $startTime,   // H:i
        public readonly ?string $endDate,
        public readonly ?string $endTime,
        public readonly array $employeeIds = [],
        public readonly ?string $title = null,
        public readonly ?string $note = null,
        public readonly int $slots = 1,
        public readonly bool $published = false,
        public readonly array $raw = [],
    ) {
    }
}
