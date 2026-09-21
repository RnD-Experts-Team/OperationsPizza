<?php

namespace App\Services\Tcp;

use App\Models\Employee;
use App\Models\Store;
use App\Models\TcpWorkSegment as WorkSegmentRow;
use App\Services\Humanity\HumanitySyncLogger;
use App\Services\Scheduling\Exceptions\SchedulingException;
use App\Services\Scheduling\StoreTimezoneResolver;
use App\Services\Tcp\Dto\TcpPunch;
use App\Services\Tcp\Dto\TcpWorkSegment;
use App\Services\Tcp\Exceptions\EmployeeNotInTcpException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Clock in / out / break, against TCP Manager+.
 *
 * TCP is the system of record for worked time, so this writes there and never
 * mirrors a punch locally as if it were fact — the local row is whatever TCP
 * came back with. A punch and the segment it produces are not the same thing:
 * TCP applies rounding rules, break deductions and approvals we do not model.
 *
 * Punches are INTERACTIVE: someone is standing at a clock. They are allowed to
 * spend the daily-quota reserve that background syncs must leave alone.
 *
 * There is no `employee_clock_states` any more, and no cache in front of it.
 * "Is this person on the clock" is an open row in `tcp_work_segments` — one
 * indexed local query, kept current by the sync regardless of where the punch
 * was made. The old table could only ever be written by this class, which is
 * precisely why a punch at a physical clock was invisible to it.
 *
 * Break punches are kept but are gated off by default (`tcp.breaks_enabled`): a
 * shift here is continuous expected work, so a break is not something the
 * business schedules. The code stays because a store or a state may yet mandate
 * a meal break — and because TCP will split a segment whether we offer the
 * button or not.
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
        // and it costs nothing. But a local "clocked in" gets one live re-check
        // before it is trusted enough to actually block a real clock-in — see
        // isClockedInLive().
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

        // See clockIn(): a local "not clocked in" gets one live re-check before
        // it is trusted enough to actually block a real clock-out.
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
     * The segment this employee is currently on, or null.
     *
     * A local read. No TCP call, no cache, no staleness window to reason about:
     * an open row IS the fact, and the ten-minute sync keeps it current whether
     * the punch was made here, at a physical clock, or in TCP's own app.
     */
    public function currentSegment(Employee $employee): ?WorkSegmentRow
    {
        if (!$employee->isLinkedToTcp()) {
            return null;
        }

        return WorkSegmentRow::query()
            ->where('employee_id', $employee->id)
            ->open()
            ->orderByDesc('starts_at_utc')
            ->first();
    }

    /**
     * Everyone on the clock at a store right now.
     *
     * One indexed query for the whole board. This used to be impossible without
     * a TCP call per employee, which a polling dashboard would have turned into
     * the entire daily quota on its own.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, WorkSegmentRow>
     */
    public function onTheClock(Store $store): \Illuminate\Database\Eloquent\Collection
    {
        return WorkSegmentRow::query()
            ->with('employee')
            ->forStore((int) $store->id)
            ->open()
            ->orderBy('starts_at_utc')
            ->get();
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

        /*
         | Mirror it immediately rather than waiting on the next sync run. No
         | extra TCP call: this is the same segment TCP's own response returned.
         |
         | Unlike before, a clock-in is NOT a no-op here — the open segment it
         | produces is exactly what makes this person show as on the clock, and
         | discarding it was why live state had to live in a separate table.
         */
        try {
            $this->workSegmentSync->syncOne($store, $employee, $segment, WorkSegmentRow::ORIGIN_PUNCH);
        } catch (\Throwable $e) {
            // TCP already accepted the punch — it is the system of record and
            // the write has happened there. A failure to mirror it locally must
            // not turn an already-successful punch into a failed response; the
            // next scheduled sync will catch it up.
            Log::warning('Failed to mirror a TCP punch locally', [
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
     * One targeted read, scoped to this employee and a tight window around the
     * punch, finds the segment TCP just created or closed so callers have a
     * real, stable id to log and store against — without it, every punch would
     * look like a duplicate with no matching row.
     *
     * Costs one extra TCP call, every time, on an account shaped like this one —
     * accepted the same way the punch itself is: interactive traffic may spend
     * the daily-quota reserve background syncs must leave alone.
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
     * Answered from our own table, which costs nothing and is right in the
     * common case — we opened and closed most of these segments ourselves, and
     * the sync fills in the ones we did not.
     *
     * A BACKDATED punch is different: it is a correction, and our picture
     * describes "now", not last Tuesday. Those always go to TCP.
     */
    private function isClockedIn(Employee $employee, string $tcpEmployeeId, CarbonImmutable $at): bool
    {
        $isCorrection = $at->lessThan(CarbonImmutable::now()->subMinutes(
            (int) config('tcp.clock_state_trust_minutes', 15)
        ));

        // A correction goes straight to TCP. Our table describes who is on the
        // clock NOW, which says nothing about last Tuesday — and answering a
        // backdated punch from it would let a duplicate segment through.
        if ($isCorrection) {
            return $this->isClockedInLive($employee, $tcpEmployeeId, $at);
        }

        return WorkSegmentRow::query()
            ->where('employee_id', $employee->id)
            ->open()
            ->exists();
    }

    /**
     * Always asks TCP directly, ignoring what we hold locally.
     *
     * Used as the confirmation right before refusing a clock-in or clock-out.
     * Our table records what we know, but the world can change it underneath us
     * — a punch corrected or deleted in TCP's own UI, for instance — and up to
     * ten minutes can pass before a sync notices. Wrongly refusing a punch costs
     * somebody a shift; the extra call costs one unit of a small daily quota.
     */
    private function isClockedInLive(Employee $employee, string $tcpEmployeeId, CarbonImmutable $at): bool
    {
        foreach ($this->tcp->listWorkSegments($at->subDay(), $at->addDay(), [$tcpEmployeeId]) as $segment) {
            if ($segment->isOpen()) {
                // Seen live and not yet in our table: record it, so the board is
                // right immediately rather than at the next sync.
                $this->mirrorQuietly($employee, $segment);

                return true;
            }
        }

        return false;
    }

    /** Best-effort: a failure to mirror must never fail the punch behind it. */
    private function mirrorQuietly(Employee $employee, TcpWorkSegment $segment): void
    {
        $store = $employee->stores->first()?->store_number;

        if ($store === null) {
            return;
        }

        try {
            $resolved = Store::query()->where('store_number', $store)->first();

            if ($resolved !== null) {
                $this->workSegmentSync->syncOne($resolved, $employee, $segment, WorkSegmentRow::ORIGIN_DISCOVERED);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to mirror a live TCP segment', [
                'employee_id' => $employee->id,
                'error' => $e->getMessage(),
            ]);
        }
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
