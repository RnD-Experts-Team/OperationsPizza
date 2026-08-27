<?php

namespace App\Services\Tcp;

use App\Models\Employee;
use App\Models\EmployeeClockState;
use App\Models\Store;
use App\Services\Humanity\HumanitySyncLogger;
use App\Services\Scheduling\Exceptions\SchedulingException;
use App\Services\Scheduling\StoreTimezoneResolver;
use App\Services\Tcp\Dto\TcpPunch;
use App\Services\Tcp\Dto\TcpWorkSegment;
use App\Services\Tcp\Exceptions\EmployeeNotInTcpException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Clock in / out / break, against TCP Manager+.
 *
 * TCP is the system of record for worked time, so this writes there and never
 * mirrors a punch locally as if it were fact — `actual_shifts` is populated by
 * TcpWorkSegmentSync reading back what TCP actually recorded. A punch and the
 * segment it produces are not the same thing: TCP applies rounding rules,
 * break deductions and approvals we do not model.
 *
 * Punches are INTERACTIVE: someone is standing at a clock. They are allowed to
 * spend the daily-quota reserve that background syncs must leave alone.
 */
class TcpClockService
{
    public function __construct(
        private readonly \App\Services\External\ExternalWriteGuard $writeGuard,
        private readonly TcpClientInterface $tcp,
        private readonly TcpJobCodeResolver $jobCodes,
        private readonly StoreTimezoneResolver $timezones,
        private readonly HumanitySyncLogger $syncLog,
        private readonly TcpWorkSegmentSync $workSegmentSync,
    ) {
    }

    public function clockIn(Store $store, Employee $employee, ?CarbonImmutable $at = null, ?string $positionLabel = null, ?Request $request = null): TcpWorkSegment
    {
        $this->writeGuard->assertAllowed((string) $store->store_number);

        $tcpEmployeeId = $this->requireTcpLink($employee);
        $at = $this->resolveMoment($store, $at);

        // Refuse locally rather than let TCP reject it: the error is clearer,
        // and it costs nothing from a very small daily quota. But a cached
        // "clocked in" gets one live re-check before it's trusted enough to
        // actually block a real clock-in — see isClockedInLive().
        if ($this->isClockedIn($employee, $tcpEmployeeId, $at) && $this->isClockedInLive($employee, $tcpEmployeeId, $at)) {
            throw new SchedulingException(
                trim("{$employee->first_name} {$employee->last_name}") . ' is already clocked in.',
                'ALREADY_CLOCKED_IN',
                409,
                ['employee_id' => (string) $employee->id]
            );
        }

        $jobCodeId = $this->jobCodes->resolve($store, $employee, $positionLabel);

        return $this->send(
            TcpPunch::clockIn($tcpEmployeeId, $jobCodeId, $at),
            $store,
            $employee,
            'clock_in',
            $request
        );
    }

    public function clockOut(Store $store, Employee $employee, ?CarbonImmutable $at = null, ?Request $request = null): TcpWorkSegment
    {
        $this->writeGuard->assertAllowed((string) $store->store_number);

        $tcpEmployeeId = $this->requireTcpLink($employee);
        $at = $this->resolveMoment($store, $at);

        // See clockIn(): a cached "not clocked in" gets one live re-check
        // before it's trusted enough to actually block a real clock-out.
        if (!$this->isClockedIn($employee, $tcpEmployeeId, $at) && !$this->isClockedInLive($employee, $tcpEmployeeId, $at)) {
            throw new SchedulingException(
                trim("{$employee->first_name} {$employee->last_name}") . ' is not clocked in.',
                'NOT_CLOCKED_IN',
                409,
                ['employee_id' => (string) $employee->id]
            );
        }

        return $this->send(
            TcpPunch::clockOut($tcpEmployeeId, $at),
            $store,
            $employee,
            'clock_out',
            $request
        );
    }

    /** A break starts by CLOSING the worked segment — TCP models it as a timeOut. */
    public function breakStart(Store $store, Employee $employee, int $breakType = 0, ?CarbonImmutable $at = null, ?Request $request = null): TcpWorkSegment
    {
        $this->writeGuard->assertAllowed((string) $store->store_number);

        $tcpEmployeeId = $this->requireTcpLink($employee);

        return $this->send(
            TcpPunch::breakStart($tcpEmployeeId, $this->resolveMoment($store, $at), $breakType),
            $store,
            $employee,
            'break_start',
            $request
        );
    }

    /** Returning from break OPENS a new segment, so it needs a job code again. */
    public function breakEnd(Store $store, Employee $employee, ?CarbonImmutable $at = null, ?string $positionLabel = null, ?Request $request = null): TcpWorkSegment
    {
        $this->writeGuard->assertAllowed((string) $store->store_number);

        $tcpEmployeeId = $this->requireTcpLink($employee);
        $jobCodeId = $this->jobCodes->resolve($store, $employee, $positionLabel);

        return $this->send(
            TcpPunch::breakEnd($tcpEmployeeId, $jobCodeId, $this->resolveMoment($store, $at)),
            $store,
            $employee,
            'break_end',
            $request
        );
    }

    /**
     * Who is currently on the clock.
     *
     * Backs GET .../clock-status, which a dashboard polls. Read paths must not
     * spend the daily quota per request, so the answer is cached briefly — a
     * poll every few seconds collapses to roughly one TCP call a minute per
     * employee. Any punch we make busts the key (see send()), so the only
     * staleness window is a punch made at a physical clock or in TCP's own app.
     *
     * Behind the cache sits the durable row rather than TCP directly, so a
     * cache flush costs nothing while that row is still inside its trust
     * window — the same window the cache-only implementation enforced.
     */
    public function currentSegment(Employee $employee): ?TcpWorkSegment
    {
        if (!$employee->isLinkedToTcp()) {
            return null;
        }

        $tcpEmployeeId = (string) $employee->tcp_employee_id;

        // Cached as an array so a miss and a "no open segment" are distinct:
        // [] means we asked and nobody is clocked in.
        $cached = Cache::remember(
            $this->openSegmentKey($tcpEmployeeId),
            (int) config('tcp.open_segment_ttl_seconds', 60),
            function () use ($employee, $tcpEmployeeId) {
                $state = $this->clockState($employee);

                if ($state !== null && $state->isFresh()) {
                    if (!$state->isClockedIn()) {
                        return [];
                    }

                    // A state recorded without its segment (a live re-check can
                    // only answer yes/no) falls through to TCP rather than
                    // reporting "clocked in" with nothing to show for it.
                    if (($segment = $state->openSegment()) !== null) {
                        return [$segment];
                    }
                }

                $now = CarbonImmutable::now();

                foreach ($this->tcp->listWorkSegments(
                    $now->subDay(),
                    $now->addDay(),
                    [$tcpEmployeeId]
                ) as $segment) {
                    if ($segment->isOpen()) {
                        $this->rememberClockState($employee, $tcpEmployeeId, true, segment: $segment);

                        return [$segment];
                    }
                }

                $this->rememberClockState($employee, $tcpEmployeeId, false);

                return [];
            }
        );

        return $cached[0] ?? null;
    }

    // ---------------------------------------------------------------- internals

    private function send(TcpPunch $punch, Store $store, Employee $employee, string $operation, ?Request $request): TcpWorkSegment
    {
        // Reuses the Humanity sync log deliberately: one audit table for every
        // external write, so an incident is investigated in one place.
        $log = $this->syncLog->begin(
            entityType: 'tcp_punch',
            operation: $operation,
            entityId: (int) $employee->id,
            storeId: (int) $store->id,
        );

        try {
            $segments = $this->tcp->punch([$punch]);
        } catch (\Throwable $e) {
            $this->syncLog->failed($log, $e);

            throw $e;
        }

        $segment = $segments[0] ?? null;

        if ($segment === null) {
            $this->syncLog->failed(
                $log,
                new Exceptions\TcpException("TCP {$operation} returned no work segment.")
            );

            throw new Exceptions\TcpException("TCP {$operation} returned no work segment.");
        }

        $segment = $this->backfillSegmentId($segment);

        $this->syncLog->succeeded($log, $segment->id);

        // We know the state exactly now, so record it rather than paying for a
        // lookup on the next punch. isOpen() is the truth from TCP's own
        // response, not an assumption about what the operation should have done.
        // The operation is passed too, because TCP cannot tell us WHY a segment
        // is closed — only a break_start knows it was a break.
        $this->rememberClockState(
            $employee,
            $punch->employeeId,
            $segment->isOpen(),
            store: $store,
            segment: $segment,
            operation: $operation,
        );

        // The segment TCP just returned IS the current one, so seed the read
        // cache with it instead of forcing clock-status to re-fetch. Covers all
        // four punch types, since every one of them lands here.
        Cache::put(
            $this->openSegmentKey($punch->employeeId),
            $segment->isOpen() ? [$segment] : [],
            (int) config('tcp.open_segment_ttl_seconds', 60)
        );

        // Mirror it into actual_shifts immediately rather than waiting on the
        // next tcp:sync-worksegments run. No extra TCP call: this is the same
        // segment TCP's own response just returned. An open segment (e.g.
        // right after a clock-in) is correctly skipped by the same rule the
        // bulk sync uses — there's no end time yet to record.
        try {
            $this->workSegmentSync->syncOne($store, $employee, $segment);
        } catch (\Throwable $e) {
            // TCP already accepted the punch — it is the system of record and
            // this write already happened there. A failure to mirror it
            // locally must not turn an already-successful punch into a
            // failed response; the next scheduled sync will catch it up.
            Log::warning('Failed to mirror a TCP punch into actual_shifts', [
                'employee_id' => $employee->id,
                'tcp_work_segment_id' => $segment->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $segment;
    }

    /**
     * A punch's own POST response never carries the segment's real id —
     * confirmed live 2026-08-26: it echoes back employeeId/timeIn/timeOut/
     * jobCodeId only, and the id only appears on a later GET /worksegments.
     * One targeted read, scoped to this employee and a tight window around
     * the punch, finds the segment TCP just created/closed so callers have a
     * real, stable id to log and mirror into actual_shifts against — without
     * it, every punch would look like a duplicate with no matching row.
     *
     * Costs one extra TCP call, every time, on an account shaped like this
     * one — accepted the same way the punch itself is: interactive traffic
     * may spend the daily-quota reserve background syncs must leave alone.
     */
    private function backfillSegmentId(TcpWorkSegment $segment): TcpWorkSegment
    {
        if ($segment->id !== '') {
            return $segment;
        }

        $anchor = CarbonImmutable::parse($segment->timeIn ?? $segment->timeOut ?? 'now');

        foreach ($this->tcp->listWorkSegments($anchor->subHour(), $anchor->addHour(), [$segment->employeeId]) as $candidate) {
            $matchesIn = $segment->timeIn === null || $candidate->timeIn === $segment->timeIn;
            $matchesOut = $segment->timeOut === null || $candidate->timeOut === $segment->timeOut;

            if ($candidate->employeeId === $segment->employeeId && $matchesIn && $matchesOut) {
                return $candidate;
            }
        }

        Log::warning('Could not backfill a TCP work segment id after a punch', [
            'employee_id' => $segment->employeeId,
            'time_in' => $segment->timeIn,
            'time_out' => $segment->timeOut,
        ]);

        return $segment;
    }

    private function requireTcpLink(Employee $employee): string
    {
        if (!$employee->isLinkedToTcp()) {
            throw new EmployeeNotInTcpException($employee);
        }

        return (string) $employee->tcp_employee_id;
    }

    /**
     * Is there an open segment around this moment?
     *
     * The window is anchored on the PUNCH, not on "now". A correction being
     * entered for last Tuesday has to find the segment opened last Tuesday —
     * a now-relative window would report "not clocked in" and let a duplicate
     * open segment through.
     *
     * Answered from a local cache when we can, because otherwise every punch
     * costs TWO requests (check, then punch) against a 2500/day quota — and a
     * busy lunch rush is exactly when we can least afford to double the spend.
     * We are the ones opening and closing these segments, so our own record of
     * the state is authoritative for the common case; the cache is only skipped
     * when we have no record, or when the punch is a backdated correction.
     */
    private function isClockedIn(Employee $employee, string $tcpEmployeeId, CarbonImmutable $at): bool
    {
        $isCorrection = $at->lessThan(CarbonImmutable::now()->subMinutes(
            (int) config('tcp.clock_state_trust_minutes', 15)
        ));

        if (!$isCorrection) {
            $known = $this->knownClockState($employee, $tcpEmployeeId);

            if ($known !== null) {
                return $known;
            }
        }

        return $this->isClockedInLive($employee, $tcpEmployeeId, $at);
    }

    /**
     * What we already know, without spending a TCP call — cache, then the
     * durable row behind it. Null means we genuinely do not know.
     *
     * The row has no TTL of its own, so freshness is enforced explicitly:
     * persisting this state must not make a stale answer any longer-lived than
     * the cache-only version allowed.
     */
    private function knownClockState(Employee $employee, string $tcpEmployeeId): ?bool
    {
        $cached = Cache::get($this->clockStateKey($tcpEmployeeId));

        if ($cached !== null) {
            return (bool) $cached;
        }

        $state = $this->clockState($employee);

        if ($state !== null && $state->isFresh()) {
            // Re-seed the cache so the next punch does not touch the DB either.
            $this->cacheClockState($tcpEmployeeId, $state->isClockedIn());

            return $state->isClockedIn();
        }

        return null;
    }

    /**
     * Always asks TCP directly, ignoring the cache.
     *
     * Used both on a cache miss above, and as a confirmation right before
     * refusing a clock-in/out on a cached belief — see clockIn()/clockOut().
     * The cache is our own record of what WE did, but the world can change
     * it underneath us (a punch corrected or deleted directly in TCP's own
     * UI, for instance), and the trust window is long enough that a stale
     * "clocked in" can otherwise block a real clock-in for up to 15 minutes
     * with nothing to self-correct it. Wrongly refusing a punch costs a
     * shift; the extra call costs one unit of a very small daily quota.
     */
    private function isClockedInLive(Employee $employee, string $tcpEmployeeId, CarbonImmutable $at): bool
    {
        foreach ($this->tcp->listWorkSegments($at->subDay(), $at->addDay(), [$tcpEmployeeId]) as $segment) {
            if ($segment->isOpen()) {
                $this->rememberClockState($employee, $tcpEmployeeId, true, segment: $segment);

                return true;
            }
        }

        $this->rememberClockState($employee, $tcpEmployeeId, false);

        return false;
    }

    /**
     * Record what we just learned, so the next punch doesn't need a lookup.
     *
     * Writes both the cache and the durable row. The cache stays short-lived on
     * purpose: someone can also punch at a physical clock or in TCP's own app,
     * and a stale "clocked in" would wrongly block them. Expiry costs one
     * lookup; being wrong costs a shift.
     */
    private function rememberClockState(
        Employee $employee,
        string $tcpEmployeeId,
        bool $clockedIn,
        ?Store $store = null,
        ?TcpWorkSegment $segment = null,
        ?string $operation = null,
    ): void {
        $this->cacheClockState($tcpEmployeeId, $clockedIn);
        $this->persistClockState($employee, $tcpEmployeeId, $clockedIn, $store, $segment, $operation);
    }

    private function cacheClockState(string $tcpEmployeeId, bool $clockedIn): void
    {
        Cache::put(
            $this->clockStateKey($tcpEmployeeId),
            $clockedIn,
            (int) config('tcp.clock_state_ttl_seconds', 900)
        );
    }

    /**
     * The durable half of the clock state.
     *
     * Best-effort for exactly the reason the actual_shifts mirror is: TCP has
     * already accepted the punch and is the system of record, so failing to
     * record it locally must not turn a successful punch into a failed
     * response.
     */
    private function persistClockState(
        Employee $employee,
        string $tcpEmployeeId,
        bool $clockedIn,
        ?Store $store,
        ?TcpWorkSegment $segment,
        ?string $operation,
    ): void {
        try {
            $existing = $this->clockState($employee);

            $status = match (true) {
                $clockedIn => EmployeeClockState::STATUS_CLOCKED_IN,
                $operation === 'break_start' => EmployeeClockState::STATUS_ON_BREAK,
                // A live re-check only sees "no open segment" — it cannot tell a
                // break from a finished shift, so it must not overwrite what the
                // punch itself told us.
                $operation === null && $existing?->status === EmployeeClockState::STATUS_ON_BREAK
                    => EmployeeClockState::STATUS_ON_BREAK,
                default => EmployeeClockState::STATUS_CLOCKED_OUT,
            };

            $onBreak = $status === EmployeeClockState::STATUS_ON_BREAK;

            EmployeeClockState::query()->updateOrCreate(
                ['employee_id' => $employee->id],
                [
                    // Preserved when a live re-check, which has no store in
                    // hand, refreshes a state a punch established.
                    'store_id' => $store?->id ?? $existing?->store_id,
                    'tcp_employee_id' => $tcpEmployeeId,
                    'status' => $status,
                    'tcp_work_segment_id' => $clockedIn ? ($segment?->id ?: null) : null,
                    'clock_in_at' => $clockedIn
                        ? ($segment?->timeIn ?? $existing?->clock_in_at ?? CarbonImmutable::now())
                        : null,
                    'break_started_at' => $onBreak
                        ? ($segment?->timeOut ?? $existing?->break_started_at ?? CarbonImmutable::now())
                        : null,
                    'open_segment' => $clockedIn ? $segment?->toArray() : null,
                    'last_synced_at' => CarbonImmutable::now(),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to persist an employee clock state', [
                'employee_id' => $employee->id,
                'tcp_employee_id' => $tcpEmployeeId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function clockState(Employee $employee): ?EmployeeClockState
    {
        return EmployeeClockState::query()
            ->where('employee_id', $employee->id)
            ->first();
    }

    private function clockStateKey(string $tcpEmployeeId): string
    {
        return "tcp:clockstate:{$tcpEmployeeId}";
    }

    /** The open segment itself, for the clock-status read path. */
    private function openSegmentKey(string $tcpEmployeeId): string
    {
        return "tcp:opensegment:{$tcpEmployeeId}";
    }

    /**
     * TCP interprets datetimes in the account's system timezone and offers no
     * per-request timezone, so a punch must be expressed in the store's local
     * wall clock — the same constraint Humanity imposes.
     */
    private function resolveMoment(Store $store, ?CarbonImmutable $at): CarbonImmutable
    {
        $timezone = $this->timezones->for($store);

        return ($at ?? CarbonImmutable::now())->setTimezone($timezone);
    }
}
