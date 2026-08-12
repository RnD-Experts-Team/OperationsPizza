<?php

namespace App\Services\Tcp\Dto;

use Carbon\CarbonImmutable;

/**
 * One entry in a POST /v1/punches array.
 *
 * TCP models clocking as an operation type rather than a boolean, and each type
 * requires a different subset of fields — sending the wrong one is a 400, so
 * the named constructors below encode which is which.
 */
final class TcpPunch
{
    public const CLOCK_IN = 'clockIn';
    public const CLOCK_OUT = 'clockOut';
    public const BREAK_START = 'breakStart';
    public const BREAK_END = 'breakEnd';
    public const CHANGE_JOB_CODE = 'changeJobCode';
    public const CHANGE_COST_CODE = 'changeCostCode';

    private function __construct(
        public readonly string $operationType,
        public readonly string $employeeId,
        public readonly ?string $jobCodeId = null,
        public readonly ?CarbonImmutable $timeIn = null,
        public readonly ?CarbonImmutable $timeOut = null,
        public readonly ?int $breakType = null,
        public readonly ?string $costCodeName = null,
    ) {
    }

    /** Requires jobCodeId + timeIn. */
    public static function clockIn(string $employeeId, string $jobCodeId, CarbonImmutable $at): self
    {
        return new self(self::CLOCK_IN, $employeeId, jobCodeId: $jobCodeId, timeIn: $at);
    }

    /** Requires timeOut only — the open segment already knows the job code. */
    public static function clockOut(string $employeeId, CarbonImmutable $at): self
    {
        return new self(self::CLOCK_OUT, $employeeId, timeOut: $at);
    }

    /** Requires timeOut + breakType: a break starts by closing the worked segment. */
    public static function breakStart(string $employeeId, CarbonImmutable $at, int $breakType): self
    {
        return new self(self::BREAK_START, $employeeId, timeOut: $at, breakType: $breakType);
    }

    /** Requires jobCodeId + timeIn: returning from break opens a new segment. */
    public static function breakEnd(string $employeeId, string $jobCodeId, CarbonImmutable $at): self
    {
        return new self(self::BREAK_END, $employeeId, jobCodeId: $jobCodeId, timeIn: $at);
    }

    public static function changeJobCode(string $employeeId, string $jobCodeId, CarbonImmutable $at): self
    {
        return new self(self::CHANGE_JOB_CODE, $employeeId, jobCodeId: $jobCodeId, timeOut: $at);
    }

    /**
     * Wire format. Datetimes are sent as local wall clock without an offset —
     * TCP interprets them in the account's system timezone and has no
     * per-request timezone parameter, exactly like Humanity.
     */
    public function toPayload(): array
    {
        return array_filter([
            'operationType' => $this->operationType,
            'employeeId' => (int) $this->employeeId,
            'jobCodeId' => $this->jobCodeId === null ? null : (int) $this->jobCodeId,
            'timeIn' => $this->timeIn?->format('Y-m-d\TH:i:s'),
            'timeOut' => $this->timeOut?->format('Y-m-d\TH:i:s'),
            'breakType' => $this->breakType,
            'costCodeName' => $this->costCodeName,
        ], fn ($value) => $value !== null);
    }
}
