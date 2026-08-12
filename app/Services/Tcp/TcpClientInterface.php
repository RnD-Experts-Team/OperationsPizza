<?php

namespace App\Services\Tcp;

use App\Services\Tcp\Dto\TcpPunch;
use App\Services\Tcp\Dto\TcpWorkSegment;
use DateTimeInterface;

/**
 * The seam between our domain and TCP Manager+ (TimeClock Plus).
 *
 * Everything TCP-specific lives behind this: client_credentials auth, the
 * x-api-key/company-id headers, the {data,errors,meta} envelope, the two-tier
 * rate limit, and the operationType punch model.
 *
 * Scope note: TCP is the system of record for employees, leave and job codes,
 * and Humanity owns only the schedule — so shift writes stay on the Humanity
 * client and never appear here.
 */
interface TcpClientInterface
{
    /** Cheap liveness + credential check. Use before a long sync. */
    public function ping(): bool;

    // ---------------------------------------------------------------- clocking

    /**
     * Clock in/out, break start/end, job-code change.
     *
     * Accepts a batch because TCP does, and because the daily quota makes one
     * request for twenty punches meaningfully cheaper than twenty requests.
     *
     * @param  array<int, TcpPunch>  $punches
     * @return array<int, TcpWorkSegment>  the resulting segments
     */
    public function punch(array $punches): array;

    // ------------------------------------------------------------ worked hours

    /**
     * Worked segments in a window.
     *
     * `updatedSince` maps to updatedOnStart — a real delta filter, which
     * Humanity has no equivalent of. Prefer it over widening the date range:
     * it is the difference between a sync that costs a handful of calls and
     * one that re-reads the fortnight every time.
     *
     * @param  array<int, string>  $employeeIds  chunked to 20 by the client
     * @return array<int, TcpWorkSegment>
     */
    public function listWorkSegments(
        DateTimeInterface $from,
        DateTimeInterface $to,
        array $employeeIds = [],
        ?DateTimeInterface $updatedSince = null,
    ): array;

    public function getWorkSegment(string $id): ?TcpWorkSegment;

    /**
     * Which employees' time cards have changed since a given moment.
     *
     * The closest thing TCP has to a notification: no webhooks exist, but this
     * costs ONE call and answers "did anything happen?" for the entire account.
     * When nothing has, a sync round costs exactly that one call instead of
     * paging every employee's segments — which is the difference between
     * affording an hourly sync and exhausting a 2500/day quota.
     *
     * Returns null for `employeeIds` when TCP reports allEmployeesChanged
     * (a bulk recalculation), meaning "fetch everyone".
     *
     * @return array{all_changed:bool, employee_ids:array<int, string>}
     */
    public function listCalculationChanges(?DateTimeInterface $since = null): array;

    // --------------------------------------------------------------- employees

    /** @return array<int, array<string, mixed>> */
    public function listEmployees(array $filters = []): array;

    public function getEmployee(string $employeeId): ?array;

    /**
     * NOTE: `employeeId` is CLIENT-SUPPLIED on create, not assigned by TCP.
     * Setting it to our own employee id is what makes the push idempotent and
     * keeps identity consistent all the way through to Humanity.
     */
    public function createEmployee(array $payload): array;

    public function updateEmployee(string $employeeId, array $payload): array;

    // --------------------------------------------------------------- job codes

    /** @return array<int, array<string, mixed>> */
    public function listJobCodes(): array;

    // ---------------------------------------------------------------- time off

    /** @return array<int, array<string, mixed>> */
    public function listTimeOffRequests(DateTimeInterface $from, DateTimeInterface $to): array;
}
