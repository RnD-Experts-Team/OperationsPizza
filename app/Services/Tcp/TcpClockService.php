<?php

namespace App\Services\Tcp;

use App\Models\Employee;
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
use Illuminate\Support\Str;

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
        if ($this->isClockedIn($tcpEmployeeId, $at) && $this->isClockedInLive($tcpEmployeeId, $at)) {
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
        if (!$this->isClockedIn($tcpEmployeeId, $at) && !$this->isClockedInLive($tcpEmployeeId, $at)) {
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
            function () use ($tcpEmployeeId) {
                $now = CarbonImmutable::now();

                foreach ($this->tcp->listWorkSegments(
                    $now->subDay(),
                    $now->addDay(),
                    [$tcpEmployeeId]
                ) as $segment) {
                    if ($segment->isOpen()) {
                        return [$segment];
                    }
                }

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
            requestPayload: $punch->toPayload(),
            correlationId: $request?->headers->get('X-Correlation-Id') ?? (string) Str::ulid(),
        );

        $startedAt = microtime(true);

        try {
            $segments = $this->tcp->punch([$punch]);
        } catch (\Throwable $e) {
            $this->syncLog->failed($log, $e, (int) round((microtime(true) - $startedAt) * 1000));

            throw $e;
        }

        $segment = $segments[0] ?? null;

        if ($segment === null) {
            $this->syncLog->failed(
                $log,
                new Exceptions\TcpException("TCP {$operation} returned no work segment."),
                (int) round((microtime(true) - $startedAt) * 1000)
            );

            throw new Exceptions\TcpException("TCP {$operation} returned no work segment.");
        }

        $this->syncLog->succeeded(
            $log,
            $segment->id,
            $segment->raw,
            (int) round((microtime(true) - $startedAt) * 1000)
        );

        // We know the state exactly now, so record it rather than paying for a
        // lookup on the next punch. isOpen() is the truth from TCP's own
        // response, not an assumption about what the operation should have done.
        $this->rememberClockState($punch->employeeId, $segment->isOpen());

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
    private function isClockedIn(string $tcpEmployeeId, CarbonImmutable $at): bool
    {
        $isCorrection = $at->lessThan(CarbonImmutable::now()->subMinutes(
            (int) config('tcp.clock_state_trust_minutes', 15)
        ));

        if (!$isCorrection) {
            $cached = Cache::get($this->clockStateKey($tcpEmployeeId));

            if ($cached !== null) {
                return (bool) $cached;
            }
        }

        return $this->isClockedInLive($tcpEmployeeId, $at);
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
    private function isClockedInLive(string $tcpEmployeeId, CarbonImmutable $at): bool
    {
        foreach ($this->tcp->listWorkSegments($at->subDay(), $at->addDay(), [$tcpEmployeeId]) as $segment) {
            if ($segment->isOpen()) {
                $this->rememberClockState($tcpEmployeeId, true);

                return true;
            }
        }

        $this->rememberClockState($tcpEmployeeId, false);

        return false;
    }

    /**
     * Record what we just did, so the next punch doesn't need a lookup.
     *
     * Short-lived on purpose: someone can also punch at a physical clock or in
     * TCP's own app, and a stale "clocked in" would wrongly block them. Expiry
     * costs one lookup; being wrong costs a shift.
     */
    private function rememberClockState(string $tcpEmployeeId, bool $clockedIn): void
    {
        Cache::put(
            $this->clockStateKey($tcpEmployeeId),
            $clockedIn,
            (int) config('tcp.clock_state_ttl_seconds', 900)
        );
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
