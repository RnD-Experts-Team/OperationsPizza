<?php

namespace App\Services\Scheduling;

use App\Models\ActualShift;
use App\Models\Employee;
use App\Models\ShiftAssignment;
use App\Models\Store;
use App\Services\External\ExternalWriteGuard;
use App\Services\Scheduling\Dto\ResolvedShiftTime;
use App\Services\Tcp\Dto\TcpWorkSegment;
use App\Services\Tcp\Dto\TcpWorkSegmentPayload;
use App\Services\Tcp\Exceptions\EmployeeNotInTcpException;
use App\Services\Tcp\Exceptions\TcpException;
use App\Services\Tcp\TcpClientInterface;
use App\Services\Tcp\TcpJobCodeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * The "what actually happened" review layer.
 *
 * Never pushed to Humanity — Humanity owns the PLAN, and worked time is not a
 * schedule. It IS pushed to TCP Manager+, which is the system of record for
 * worked hours: a manager amending yesterday's times on the dashboard is
 * correcting payroll data, and payroll data lives in TCP.
 *
 * So this writes the same way ShiftWriteService does, just to the other vendor:
 *
 *   guard -> resolve -> write to TCP -> mirror what TCP came back with
 *
 * The TCP call happens OUTSIDE the transaction, and a failure rejects the whole
 * request rather than leaving a local row TCP has never heard of. That
 * no-orphans rule is the entire point: the reason a manager's correction used
 * to be silently reverted an hour later was that it only ever existed here,
 * while `tcp:sync-worksegments` kept reading TCP's unchanged value back over
 * the top of it.
 *
 * Two things deliberately stay local, because TCP cannot represent them:
 *   - an ABSENCE, which is the absence of worked time (marking one therefore
 *     DELETES the segment rather than writing a zero-length one);
 *   - the link to the planned assignment, which is our concept, not TCP's.
 */
class ActualShiftService
{
    public function __construct(
        private readonly ShiftTimeResolver $times,
        private readonly StoreTimezoneResolver $timezones,
        private readonly ExternalWriteGuard $writeGuard,
        private readonly TcpClientInterface $tcp,
        private readonly TcpJobCodeResolver $jobCodes,
    ) {
    }

    /** One click: "they worked exactly what was planned". */
    public function confirmPlanned(Store $store, ShiftAssignment $assignment, ?int $userId = null): ActualShift
    {
        $shift = $assignment->shift;

        return $this->upsert($store, [
            'employee_id' => (int) $assignment->employee_id,
            'shift_assignment_id' => (int) $assignment->id,
            'shift_date' => $shift->shift_date->toDateString(),
            'start_time' => substr((string) $shift->start_time, 0, 5),
            'end_time' => substr((string) $shift->end_time, 0, 5),
            'label' => $shift->label,
            'shift_type' => $shift->shift_type,
            'review_state' => ActualShift::REVIEW_WORKED,
        ], $userId);
    }

    public function markAbsent(Store $store, ShiftAssignment $assignment, ?string $note = null, ?int $userId = null): ActualShift
    {
        $shift = $assignment->shift;

        return $this->upsert($store, [
            'employee_id' => (int) $assignment->employee_id,
            'shift_assignment_id' => (int) $assignment->id,
            'shift_date' => $shift->shift_date->toDateString(),
            'start_time' => substr((string) $shift->start_time, 0, 5),
            'end_time' => substr((string) $shift->end_time, 0, 5),
            'label' => $shift->label,
            'shift_type' => $shift->shift_type,
            'review_state' => ActualShift::REVIEW_ABSENT,
            'note' => $note,
        ], $userId);
    }

    /**
     * Mark an entry that already exists as a no-show.
     *
     * Routed through upsert() rather than updating the row directly, because
     * an absence has to reach TCP too: the segment is deleted there, and a
     * verdict flipped only in our own table would be paid out anyway.
     */
    public function markAbsentEntry(Store $store, ActualShift $actual, ?string $note = null, ?int $userId = null): ActualShift
    {
        return $this->upsert($store, [
            'id' => (int) $actual->id,
            'employee_id' => (int) $actual->employee_id,
            'shift_assignment_id' => $actual->shift_assignment_id,
            'shift_date' => $actual->shift_date->toDateString(),
            'start_time' => substr((string) $actual->start_time, 0, 5),
            'end_time' => substr((string) $actual->end_time, 0, 5),
            'label' => $actual->label,
            'shift_type' => $actual->shift_type,
            'review_state' => ActualShift::REVIEW_ABSENT,
            'note' => $note ?? $actual->note,
        ], $userId);
    }

    /**
     * Remove an entry entirely — and the hours behind it.
     *
     * TCP goes first, for the same reason ShiftWriteService deletes Humanity
     * first: a row that vanished from the manager's grid but survived in
     * payroll is the worst divergence available, because nobody is looking at
     * it any more.
     */
    public function delete(Store $store, ActualShift $actual): void
    {
        if (filled($actual->tcp_work_segment_id)) {
            $this->writeGuard->assertAllowed((string) $store->store_number);
            $this->deleteSegment((string) $actual->tcp_work_segment_id);
        }

        $actual->delete();
    }

    /**
     * Create or amend an actual entry.
     *
     * `time_variance` is DERIVED here and never accepted from a client —
     * letting the caller assert how an entry compares to its plan is how the
     * two drift apart. `review_state` is the opposite: only an explicit action
     * sets it, and nothing infers it.
     *
     * Pass `id` to amend a SPECIFIC entry. Ad-hoc coverage has no planned
     * assignment behind it, so the assignment key below cannot identify it and
     * every edit used to fall through to create() — one extra row per save.
     */
    public function upsert(Store $store, array $data, ?int $userId = null): ActualShift
    {
        $timezone = $this->timezones->for($store);

        $time = $this->times->resolve(
            $data['shift_date'],
            $data['start_time'],
            $data['end_time'],
            $timezone
        );

        $assignment = isset($data['shift_assignment_id'])
            ? ShiftAssignment::query()->with('shift')->find($data['shift_assignment_id'])
            : null;

        $existing = $this->locateExisting($store, $data, $assignment, $time);

        $reviewState = $this->resolveReviewState($data, $existing);

        // DERIVED, always. Recomputed on every write from the data itself, so
        // it can never disagree with the times sitting next to it.
        $variance = $this->deriveVariance($assignment, $data);

        // TCP first, and outside any transaction — never hold one open across a
        // network round trip. Anything that fails here throws, so no local row
        // is left claiming hours TCP does not have.
        $segment = $this->pushToTcp($store, $data, $time, $reviewState, $existing);

        // TCP applies rounding and break rules we do not model, so what it
        // stored is the truth about WHEN — not what we asked for. The review
        // metadata around it (label, the planned link, the verdict) is ours,
        // because TCP has nowhere to put any of it.
        $time = $this->timeFromSegment($store, $segment) ?? $time;

        $attributes = $time->toAttributes() + [
            'store_id' => $store->id,
            'employee_id' => $data['employee_id'],
            'shift_assignment_id' => $assignment?->id,
            'label' => $data['label'] ?? null,
            'shift_type' => $data['shift_type'] ?? 'custom',
            'time_variance' => $variance,
            'review_state' => $reviewState,
            'note' => $data['note'] ?? null,
            // Never silently relabel where an entry came from. An amended
            // timeclock row is still a timeclock row — the manager corrected a
            // punch, they did not hand-enter the shift.
            'source' => $data['source'] ?? $existing?->source ?? 'manual',
            // Cleared on an absence: the segment has just been deleted from
            // TCP, so keeping its id would point at nothing and make the next
            // sync think this row is a timeclock import.
            'tcp_work_segment_id' => $segment?->id ?: ($reviewState === ActualShift::REVIEW_ABSENT ? null : $existing?->tcp_work_segment_id),
            'reviewed_by_user_id' => $userId,
            'reviewed_at' => now(),
        ];

        if ($existing !== null) {
            $existing->update($attributes);

            return $existing->refresh();
        }

        return ActualShift::query()->create($attributes);
    }

    /**
     * Make TCP agree with what the manager just said, and hand back the
     * segment it ended up holding.
     *
     * Returns null when there should be no segment at all — an absence, or a
     * store/employee this service is not allowed to write for.
     */
    private function pushToTcp(
        Store $store,
        array $data,
        ResolvedShiftTime $time,
        string $reviewState,
        ?ActualShift $existing,
    ): ?TcpWorkSegment {
        $segmentId = $existing?->tcp_work_segment_id;

        // A no-show is the ABSENCE of worked time. TCP models absence as leave,
        // not as a segment, so the honest write is to remove the segment — and
        // leaving it would keep paying someone who never turned up.
        if ($reviewState === ActualShift::REVIEW_ABSENT) {
            if (filled($segmentId)) {
                $this->writeGuard->assertAllowed((string) $store->store_number);
                $this->deleteSegment((string) $segmentId);
            }

            return null;
        }

        // Nothing TCP models has changed, so there is nothing to say to it.
        // This is what makes attaching a clock-in to its planned shift free:
        // that is a LINKING operation in our own table, and re-pushing the
        // segment would replace the employee's real punch — actual punch times,
        // missed-punch flags and all — with a manager-entered copy of itself.
        // It also keeps a very small daily quota for changes that are real.
        if (filled($segmentId) && $this->matchesStoredSegment($existing, $time, $data)) {
            return null;
        }

        $this->writeGuard->assertAllowed((string) $store->store_number);

        $employee = $this->resolveEmployee($store, (int) $data['employee_id']);
        $tcpEmployeeId = $this->requireTcpLink($employee);

        $payload = new TcpWorkSegmentPayload(
            employeeId: $tcpEmployeeId,
            // Required by TCP on update as well as create — a PUT replaces the
            // whole model, so an amendment has to carry it even when only the
            // times changed.
            jobCodeId: $this->jobCodes->resolve($store, $employee, $data['position_label'] ?? null),
            timeIn: $time->startsLocal,
            timeOut: $time->endsLocal,
            note: $data['note'] ?? null,
            // A reviewed entry is, by definition, manager-approved.
            managerApproval: true,
        );

        if (filled($segmentId)) {
            try {
                return $this->tcp->updateWorkSegment((string) $segmentId, $payload);
            } catch (TcpException $e) {
                // Anything but "it isn't there" is a real failure and must
                // still reject the write.
                if ($e->httpStatus !== 404) {
                    throw $e;
                }

                // Someone deleted the segment in TCP's own UI, so our id is
                // stale. Recreating is what the manager asked for either way;
                // failing them over our own bookkeeping would not be.
                Log::info('TCP work segment vanished upstream; recreating it', [
                    'tcp_work_segment_id' => $segmentId,
                    'store_number' => $store->store_number,
                ]);
            }
        }

        return $this->tcp->createWorkSegment($payload);
    }

    /**
     * Whether TCP already holds exactly what this write would send.
     *
     * Only the fields TCP actually stores count — the times and the note.
     * Label, the verdict and the planned link are ours alone, so changing one of
     * those is not a reason to spend a call.
     */
    private function matchesStoredSegment(?ActualShift $existing, ResolvedShiftTime $time, array $data): bool
    {
        if ($existing === null) {
            return false;
        }

        $sameTimes = $existing->starts_at_utc?->equalTo($time->startsAtUtc)
            && $existing->ends_at_utc?->equalTo($time->endsAtUtc);

        $sameNote = trim((string) ($data['note'] ?? '')) === trim((string) $existing->note);

        return (bool) $sameTimes && $sameNote;
    }

    /** A segment that is already gone is the outcome we wanted. */
    private function deleteSegment(string $segmentId): void
    {
        try {
            $this->tcp->deleteWorkSegment($segmentId);
        } catch (TcpException $e) {
            if ($e->httpStatus !== 404) {
                throw $e;
            }
        }
    }

    /**
     * Re-derive our stored representation from the segment TCP actually holds.
     *
     * Goes back through ShiftTimeResolver rather than subtracting the two
     * strings, so `duration_minutes` stays the true UTC delta on the two DST
     * days a year — the same rule the planned side follows.
     */
    private function timeFromSegment(Store $store, ?TcpWorkSegment $segment): ?ResolvedShiftTime
    {
        if ($segment === null || $segment->timeIn === null || $segment->timeOut === null) {
            return null;
        }

        $timezone = $this->timezones->for($store);

        $startsLocal = CarbonImmutable::parse($segment->timeIn, $timezone);
        $endsLocal = CarbonImmutable::parse($segment->timeOut, $timezone);

        if ($endsLocal <= $startsLocal) {
            return null;
        }

        return $this->times->resolve(
            $startsLocal->format('Y-m-d'),
            $startsLocal->format('H:i'),
            $endsLocal->format('H:i'),
            $timezone
        );
    }

    private function resolveEmployee(Store $store, int $employeeId): Employee
    {
        $employee = Employee::query()
            ->assignedToStore((string) $store->store_number)
            ->find($employeeId);

        if ($employee === null) {
            throw new Exceptions\SchedulingException(
                "Employee {$employeeId} is not assigned to store {$store->store_number}.",
                'EMPLOYEE_NOT_IN_STORE',
                404,
                ['employee_id' => $employeeId]
            );
        }

        return $employee;
    }

    /**
     * Worked hours can only be attributed to someone TCP knows about, and
     * HiringPizza owns that write — never this service.
     */
    private function requireTcpLink(Employee $employee): string
    {
        if (!$employee->isLinkedToTcp()) {
            throw new EmployeeNotInTcpException($employee);
        }

        return (string) $employee->tcp_employee_id;
    }

    /**
     * The row this write belongs to, in order of how strongly each key
     * identifies one:
     *
     *   `id`         the manager is editing THIS entry — the only key ad-hoc
     *                coverage has, since it has no planned counterpart;
     *   assignment   one actual per planned assignment, so re-reviewing a
     *                shift amends rather than stacking duplicates;
     *   otherwise    nothing matches yet, and this is a new entry.
     */
    private function locateExisting(
        Store $store,
        array $data,
        ?ShiftAssignment $assignment,
        ResolvedShiftTime $time,
    ): ?ActualShift
    {
        if (filled($data['id'] ?? null)) {
            return ActualShift::query()
                ->where('store_id', $store->id)
                ->find($data['id']);
        }

        if ($assignment !== null) {
            return ActualShift::query()
                ->where('shift_assignment_id', $assignment->id)
                ->first();
        }

        // Ad-hoc coverage identical to something already recorded is a
        // double-submit, not a second shift — the same person cannot work the
        // same hours twice, and stacking them would double the worked total.
        // Anything that differs by so much as a minute still creates.
        //
        // Matched on the UTC instants, never the TIME columns: these shifts
        // routinely cross midnight, and that is the house rule everywhere else.
        return ActualShift::query()
            ->where('store_id', $store->id)
            ->where('employee_id', $data['employee_id'])
            ->whereNull('shift_assignment_id')
            ->where('starts_at_utc', $time->startsAtUtc)
            ->where('ends_at_utc', $time->endsAtUtc)
            ->first();
    }

    /**
     * The human verdict on this entry. ASSERTED — nothing here inspects a time.
     *
     * Three cases, in order:
     *
     *  1. An explicit `review_state` wins. That is a deliberate action —
     *     confirm-actual, absent-actual, or a client sending one outright — and
     *     it is the ONLY way out of an absence.
     *  2. An existing absence is preserved. Correcting the note on a no-show is
     *     not a statement about attendance, and silently promoting it to
     *     `worked` is exactly the bug that let an edit record someone as having
     *     worked a shift they never turned up for.
     *  3. Otherwise `worked`. Anything else reaching this method is a person
     *     recording or amending worked time — including a manager amending a
     *     clock-in that arrived `unreviewed`, which is precisely the act of
     *     reviewing it.
     */
    private function resolveReviewState(array $data, ?ActualShift $existing): string
    {
        if (filled($data['review_state'] ?? null)) {
            return (string) $data['review_state'];
        }

        if ($existing?->review_state === ActualShift::REVIEW_ABSENT) {
            return ActualShift::REVIEW_ABSENT;
        }

        return ActualShift::REVIEW_WORKED;
    }

    /**
     * How this entry compares to the plan behind it — nothing more.
     *
     * Purely a function of the data, with no memory of what it was before. The
     * absence special-case this replaced existed only because one column had to
     * carry both a comparison and a human's verdict; now that `review_state`
     * holds the verdict, an absence is simply not this method's business.
     */
    private function deriveVariance(?ShiftAssignment $assignment, array $data): string
    {
        if ($assignment?->shift === null) {
            return ActualShift::VARIANCE_UNPLANNED;
        }

        $shift = $assignment->shift;

        $sameTimes = substr((string) $shift->start_time, 0, 5) === substr((string) $data['start_time'], 0, 5)
            && substr((string) $shift->end_time, 0, 5) === substr((string) $data['end_time'], 0, 5);

        // The plan includes what the shift was CALLED, as
        // create_actual_shifts_table documents. Comparing times alone reported
        // a renamed shift as matching.
        $sameLabel = $this->normalizeLabel($data['label'] ?? null) === $this->normalizeLabel($shift->label);

        return $sameTimes && $sameLabel
            ? ActualShift::VARIANCE_MATCHES
            : ActualShift::VARIANCE_DIFFERS;
    }

    /** An empty label and no label are the same thing to a manager. */
    private function normalizeLabel(?string $label): string
    {
        return trim((string) $label);
    }

    public function present(ActualShift $actual, ?int $dayIndex = null): array
    {
        return [
            'id' => (string) $actual->id,
            'employee_id' => (string) $actual->employee_id,
            'planned_shift_id' => $actual->shift_assignment_id === null ? null : (string) $actual->shift_assignment_id,
            'shift_date' => $actual->shift_date?->toDateString(),
            'day_index' => $dayIndex,
            'start_time' => substr((string) $actual->start_time, 0, 5),
            'end_time' => substr((string) $actual->end_time, 0, 5),
            'duration_minutes' => (int) $actual->duration_minutes,
            'label' => $actual->label,
            'type' => $actual->shift_type,
            // The pre-split string, so no client had to change when the two
            // axes below replaced it. Prefer the axes in new work.
            'status' => $actual->legacyStatus(),
            'time_variance' => $actual->time_variance,
            'review_state' => $actual->review_state,
            'note' => $actual->note,
            'source' => $actual->source,
        ];
    }
}
