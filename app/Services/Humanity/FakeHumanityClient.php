<?php

namespace App\Services\Humanity;

use App\Services\Humanity\Dto\HumanityShiftPayload;
use App\Services\Humanity\Dto\HumanityShiftResult;
use App\Services\Humanity\Exceptions\HumanityException;
use App\Services\Humanity\Exceptions\HumanityRateLimitException;
use DateTimeInterface;

/**
 * In-memory Humanity double.
 *
 * This is the default driver, and it is what lets Phase 1 be built and verified
 * before anyone has registered an app in Humanity's UI. It deliberately mimics
 * the behaviours that break integrations rather than the happy path only:
 * ids are strings, shifts hold multiple employees, and failures can be armed
 * per-operation to prove the write-through actually rolls back.
 */
class FakeHumanityClient implements HumanityClientInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $locations = [];

    /** @var array<string, array<string, mixed>> */
    public array $positions = [];

    /** @var array<string, array<string, mixed>> */
    public array $employees = [];

    /** @var array<string, HumanityShiftResult> */
    public array $shifts = [];

    /** @var array<int, array<string, mixed>> */
    public array $leave = [];

    /** @var array<int, array<string, mixed>> */
    public array $timeClocks = [];

    /** @var array<int, array{op:string, args:array}> */
    public array $calls = [];

    private int $nextShiftId = 1000;

    /** Operation name → exception to throw on its next invocation. */
    private array $failures = [];

    // ------------------------------------------------------------ test rigging

    public function failNext(string $operation, ?\Throwable $exception = null): void
    {
        $this->failures[$operation] = $exception ?? new HumanityException("Simulated Humanity failure on {$operation}");
    }

    public function throttleNext(string $operation): void
    {
        $this->failNext($operation, new HumanityRateLimitException('Simulated throttle', 91, 200));
    }

    public function seedLocation(string $id, string $name = 'Test Location', ?string $timezone = 'America/Chicago'): void
    {
        $this->locations[$id] = ['id' => $id, 'name' => $name, 'timezone' => $timezone, 'raw' => []];
    }

    public function seedPosition(string $id, string $name, ?string $locationId = null): void
    {
        $this->positions[$id] = [
            'id' => $id,
            'name' => $name,
            'location_id' => $locationId,
            'is_active' => true,
            'updated_at' => null,
            'color' => null,
            'raw' => [],
        ];
    }

    public function seedEmployee(string $id, array $attributes = []): void
    {
        $this->employees[$id] = array_merge([
            'id' => $id,
            'eid' => null,
            'name' => "Employee {$id}",
            'email' => null,
        ], $attributes);
    }

    public function seedShift(HumanityShiftResult $shift): void
    {
        $this->shifts[$shift->shiftId] = $shift;
    }

    // ---------------------------------------------------------------- catalog

    public function listLocations(?DateTimeInterface $updatedSince = null): array
    {
        $this->guard('listLocations');

        return array_values($this->locations);
    }

    public function listPositions(?DateTimeInterface $updatedSince = null): array
    {
        $this->guard('listPositions');

        return array_values($this->positions);
    }

    // -------------------------------------------------------------- employees

    public function listEmployees(bool $includeInactive = false): array
    {
        $this->guard('listEmployees');

        return array_values($this->employees);
    }

    public function getEmployee(string $humanityEmployeeId): ?array
    {
        $this->guard('getEmployee');

        return $this->employees[$humanityEmployeeId] ?? null;
    }

    public function findEmployeeByEid(string $eid): ?array
    {
        $this->guard('findEmployeeByEid');

        foreach ($this->employees as $employee) {
            if (($employee['eid'] ?? null) !== null && (string) $employee['eid'] === $eid) {
                return $employee;
            }
        }

        return null;
    }

    // ----------------------------------------------------------------- shifts

    public function listShifts(
        string $locationId,
        DateTimeInterface $from,
        DateTimeInterface $to,
        ?string $positionId = null
    ): array {
        $this->guard('listShifts');

        $fromDate = $from->format('Y-m-d');
        $toDate = $to->format('Y-m-d');

        return array_values(array_filter($this->shifts, function (HumanityShiftResult $shift) use ($locationId, $fromDate, $toDate, $positionId) {
            if ($shift->locationId !== null && $shift->locationId !== $locationId) {
                return false;
            }

            if ($positionId !== null && $shift->positionId !== $positionId) {
                return false;
            }

            return $shift->startDate !== null
                && $shift->startDate >= $fromDate
                && $shift->startDate <= $toDate;
        }));
    }

    public function getShift(string $humanityShiftId): ?HumanityShiftResult
    {
        $this->guard('getShift');

        return $this->shifts[$humanityShiftId] ?? null;
    }

    public function createShift(HumanityShiftPayload $payload): HumanityShiftResult
    {
        $this->guard('createShift');

        $id = (string) $this->nextShiftId++;

        $shift = new HumanityShiftResult(
            shiftId: $id,
            positionId: $payload->positionId,
            locationId: $payload->locationId,
            startDate: $payload->startsLocal->format('Y-m-d'),
            startTime: $payload->startsLocal->format('H:i'),
            endDate: $payload->endsLocal->format('Y-m-d'),
            endTime: $payload->endsLocal->format('H:i'),
            employeeIds: $payload->employeeIds,
            title: $payload->title,
            note: $payload->note,
            slots: $payload->slots,
            published: $payload->published,
        );

        $this->shifts[$id] = $shift;
        $this->calls[] = ['op' => 'createShift', 'args' => ['id' => $id]];

        return $shift;
    }

    public function updateShift(string $humanityShiftId, HumanityShiftPayload $payload): HumanityShiftResult
    {
        $this->guard('updateShift');

        if (!isset($this->shifts[$humanityShiftId])) {
            throw new HumanityException("Humanity update shift failed: shift {$humanityShiftId} not found");
        }

        $existing = $this->shifts[$humanityShiftId];

        $shift = new HumanityShiftResult(
            shiftId: $humanityShiftId,
            positionId: $payload->positionId,
            locationId: $payload->locationId,
            startDate: $payload->startsLocal->format('Y-m-d'),
            startTime: $payload->startsLocal->format('H:i'),
            endDate: $payload->endsLocal->format('Y-m-d'),
            endTime: $payload->endsLocal->format('H:i'),
            // updateShift does not touch staffing in the real API either —
            // that is what assign/unassign are for.
            employeeIds: $existing->employeeIds,
            title: $payload->title,
            note: $payload->note,
            slots: $payload->slots,
            published: $payload->published,
        );

        $this->shifts[$humanityShiftId] = $shift;
        $this->calls[] = ['op' => 'updateShift', 'args' => ['id' => $humanityShiftId]];

        return $shift;
    }

    public function deleteShift(string $humanityShiftId): void
    {
        $this->guard('deleteShift');

        unset($this->shifts[$humanityShiftId]);
        $this->calls[] = ['op' => 'deleteShift', 'args' => ['id' => $humanityShiftId]];
    }

    public function assignEmployees(string $humanityShiftId, array $humanityEmployeeIds, bool $force = false): HumanityShiftResult
    {
        $this->guard('assignEmployees');

        return $this->replaceEmployees(
            $humanityShiftId,
            fn (array $current) => array_values(array_unique([...$current, ...$humanityEmployeeIds])),
            'assignEmployees'
        );
    }

    public function unassignEmployees(string $humanityShiftId, array $humanityEmployeeIds): HumanityShiftResult
    {
        $this->guard('unassignEmployees');

        return $this->replaceEmployees(
            $humanityShiftId,
            fn (array $current) => array_values(array_diff($current, $humanityEmployeeIds)),
            'unassignEmployees'
        );
    }

    // ------------------------------------------------------- leave & timeclock

    public function listLeave(DateTimeInterface $from, DateTimeInterface $to, ?string $locationId = null): array
    {
        $this->guard('listLeave');

        return $this->leave;
    }

    public function listTimeClocks(DateTimeInterface $from, DateTimeInterface $to, ?string $locationId = null): array
    {
        $this->guard('listTimeClocks');

        return $this->timeClocks;
    }

    // ---------------------------------------------------------------- internals

    private function replaceEmployees(string $shiftId, callable $mutate, string $op): HumanityShiftResult
    {
        if (!isset($this->shifts[$shiftId])) {
            throw new HumanityException("Humanity {$op} failed: shift {$shiftId} not found");
        }

        $existing = $this->shifts[$shiftId];

        $shift = new HumanityShiftResult(
            shiftId: $existing->shiftId,
            positionId: $existing->positionId,
            locationId: $existing->locationId,
            startDate: $existing->startDate,
            startTime: $existing->startTime,
            endDate: $existing->endDate,
            endTime: $existing->endTime,
            employeeIds: $mutate($existing->employeeIds),
            title: $existing->title,
            note: $existing->note,
            slots: $existing->slots,
            published: $existing->published,
        );

        $this->shifts[$shiftId] = $shift;
        $this->calls[] = ['op' => $op, 'args' => ['id' => $shiftId]];

        return $shift;
    }

    /** Mutating operations, which must honour the write flag like the real client. */
    private const WRITE_OPERATIONS = [
        'createShift', 'updateShift', 'deleteShift', 'assignEmployees', 'unassignEmployees',
    ];

    private function guard(string $operation): void
    {
        // The double enforces the same write flag as HumanityHttpClient, so the
        // safety gate is exercised by tests and behaves identically in local
        // development regardless of driver.
        if (in_array($operation, self::WRITE_OPERATIONS, true) && !config('humanity.writes_enabled')) {
            throw new HumanityException(
                "Humanity writes are disabled (HUMANITY_WRITES_ENABLED=false); refusing to {$operation}."
            );
        }

        if (isset($this->failures[$operation])) {
            $exception = $this->failures[$operation];
            unset($this->failures[$operation]);

            throw $exception;
        }
    }
}
