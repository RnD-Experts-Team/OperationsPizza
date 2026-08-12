<?php

namespace App\Services\Tcp\Dto;

/**
 * A worked segment from GET /v1/worksegments — the raw material for
 * `actual_shifts`.
 *
 * Note the two pairs of times. `timeIn`/`timeOut` are the segment as it now
 * stands, including any manager edit or rounding rule; `actualTimeIn`/
 * `actualTimeOut` are what the employee physically punched. Payroll cares about
 * the former, disputes about the latter, so both are kept.
 */
final class TcpWorkSegment
{
    public function __construct(
        public readonly string $id,
        public readonly string $employeeId,
        public readonly ?string $jobCodeId,
        public readonly ?string $timeIn,
        public readonly ?string $timeOut,
        public readonly ?string $actualTimeIn = null,
        public readonly ?string $actualTimeOut = null,
        public readonly bool $missedInPunch = false,
        public readonly bool $missedOutPunch = false,
        public readonly ?string $breakLength = null,
        public readonly array $shiftNotes = [],
        public readonly ?string $updatedOn = null,
        public readonly array $raw = [],
    ) {
    }

    /** An open segment: clocked in, not yet out. */
    public function isOpen(): bool
    {
        return $this->timeIn !== null && $this->timeOut === null;
    }

    /**
     * A segment TCP itself flags as incomplete. Worth surfacing rather than
     * importing as fact — a missed punch usually means the recorded time is a
     * default, not something the employee actually did.
     */
    public function hasMissedPunch(): bool
    {
        return $this->missedInPunch || $this->missedOutPunch;
    }

    public function note(): ?string
    {
        $notes = array_filter($this->shiftNotes, 'is_string');

        return $notes === [] ? null : implode(' | ', $notes);
    }
}
