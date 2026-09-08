<?php

namespace App\Services\Tcp\Dto;

use Carbon\CarbonImmutable;

/**
 * A WorkSegmentRequest body, for POST /v1/worksegments and
 * PUT /v1/worksegments/{id}.
 *
 * This is the OTHER way worked time reaches TCP. A punch (TcpPunch) is an
 * event — "clock this person in NOW" — and cannot express a correction to a
 * segment that already closed. A segment write states the finished fact
 * outright, which is what a manager amending yesterday's hours is actually
 * doing.
 *
 * `employeeId` and `jobCodeId` are required by TCP on BOTH create and update:
 * a PUT is a whole-model replace, not a patch, so an amendment has to carry the
 * job code even when only the times changed.
 */
final class TcpWorkSegmentPayload
{
    public function __construct(
        public readonly string $employeeId,
        public readonly string $jobCodeId,
        public readonly CarbonImmutable $timeIn,
        public readonly CarbonImmutable $timeOut,
        public readonly ?string $note = null,
        /** Our review IS a manager approval, so it is recorded as one. */
        public readonly ?bool $managerApproval = null,
    ) {
    }

    /**
     * Wire format.
     *
     * Datetimes are sent as local wall clock with NO offset, exactly as
     * TcpPunch does: TCP has no per-request timezone parameter and interprets
     * what it is given in the account's own system timezone. Attaching an
     * offset here would make an amendment land at a different moment than the
     * punch it is correcting.
     */
    public function toPayload(): array
    {
        $note = $this->note === null || trim($this->note) === '' ? null : trim($this->note);

        return array_filter([
            'employeeId' => $this->employeeId,
            'jobCodeId' => $this->jobCodeId,
            'timeIn' => $this->timeIn->format('Y-m-d\TH:i:s'),
            'timeOut' => $this->timeOut->format('Y-m-d\TH:i:s'),
            // TCP holds notes as a LIST of strings; we keep one.
            'shiftNotes' => $note === null ? null : [$note],
            'managerApproval' => $this->managerApproval,
        ], fn ($value) => $value !== null);
    }
}
