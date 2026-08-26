<?php

namespace App\Services\Humanity;

use App\Services\Humanity\Dto\HumanityShiftPayload;
use App\Services\Humanity\Dto\HumanityShiftResult;
use DateTimeInterface;

/**
 * The seam between our domain and TCP Humanity.
 *
 * Everything Humanity-specific lives behind this: the three-format date
 * handling, the body-status-code error protocol, the `schedule`-means-position
 * naming, and the update_* flag machinery. Nothing above this layer should know
 * any of it.
 */
interface HumanityClientInterface
{
    // ---------------------------------------------------------------- catalog

    /**
     * NOTE: the primary location is EXCLUDED by default; the implementation
     * must pass filter[include_primary]=1 or a store silently goes missing.
     *
     * @return array<int, array{id:string,name:string,timezone:?string}>
     */
    public function listLocations(?DateTimeInterface $updatedSince = null): array;

    /**
     * Humanity Positions — the container a shift belongs to, spelled `schedule`
     * in shift requests, and rendered as "department" in our UI.
     *
     * @return array<int, array{id:string,name:string,location_id:?string,is_active:bool,updated_at:?string}>
     */
    public function listPositions(?DateTimeInterface $updatedSince = null): array;

    // -------------------------------------------------------------- employees

    /** @return array<int, array<string, mixed>> */
    public function listEmployees(bool $includeInactive = false): array;

    public function getEmployee(string $humanityEmployeeId): ?array;

    /**
     * Look up by `eid` — the field TCP's own connector sets to the TCP
     * employee id when it propagates someone into Humanity (confirmed live:
     * Humanity's own `id`, the one visible in its links/URLs, is a separate,
     * Humanity-internal value and does NOT match TCP's id — only `eid` does).
     * ShiftWriteService::resolveHumanityIdLive() uses this for a single
     * targeted lookup instead of waiting on the daily humanity:sync-employees
     * batch job.
     */
    public function findEmployeeByEid(string $eid): ?array;

    // ----------------------------------------------------------------- shifts

    /**
     * There is no pagination on shifts and no `updated_at` filter, so callers
     * slice by date instead.
     *
     * @return array<int, HumanityShiftResult>
     */
    public function listShifts(
        string $locationId,
        DateTimeInterface $from,
        DateTimeInterface $to,
        ?string $positionId = null
    ): array;

    public function getShift(string $humanityShiftId): ?HumanityShiftResult;

    public function createShift(HumanityShiftPayload $payload): HumanityShiftResult;

    public function updateShift(string $humanityShiftId, HumanityShiftPayload $payload): HumanityShiftResult;

    /** One at a time — Humanity has no bulk delete. */
    public function deleteShift(string $humanityShiftId): void;

    /**
     * Assignment is a PUT on the shift with add/remove CSV lists; there is no
     * /shifts/{id}/employees sub-resource. $force bypasses Humanity's own
     * conflict checks.
     */
    public function assignEmployees(string $humanityShiftId, array $humanityEmployeeIds, bool $force = false): HumanityShiftResult;

    public function unassignEmployees(string $humanityShiftId, array $humanityEmployeeIds): HumanityShiftResult;

    // ------------------------------------------------------- leave & timeclock

    /** @return array<int, array<string, mixed>> */
    public function listLeave(DateTimeInterface $from, DateTimeInterface $to, ?string $locationId = null): array;

    /** @return array<int, array<string, mixed>> */
    public function listTimeClocks(DateTimeInterface $from, DateTimeInterface $to, ?string $locationId = null): array;
}
