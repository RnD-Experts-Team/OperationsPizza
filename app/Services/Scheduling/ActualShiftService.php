<?php

namespace App\Services\Scheduling;

use App\Models\ActualShift;
use App\Models\Employee;
use App\Models\ShiftAssignment;
use App\Models\Store;
use App\Models\TcpWorkSegment as WorkSegmentRow;
use App\Services\External\ExternalWriteGuard;
use App\Services\Scheduling\Dto\ResolvedShiftTime;
use App\Services\Tcp\Dto\TcpWorkSegment;
use App\Services\Tcp\Dto\TcpWorkSegmentPayload;
use App\Services\Tcp\Exceptions\EmployeeNotInTcpException;
use App\Services\Tcp\Exceptions\TcpException;
use App\Services\Tcp\TcpClientInterface;
use App\Services\Tcp\TcpJobCodeResolver;
use App\Services\Tcp\TcpWorkSegmentSync;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The "what actually happened" review layer.
 *
 * Never pushed to Humanity — Humanity owns the PLAN, and worked time is not a
 * schedule. It IS pushed to TCP Manager+, which is the system of record for
 * worked hours: a manager amending yesterday's times is correcting payroll data,
 * and payroll data lives in TCP.
 *
 *   guard -> write to TCP -> mirror what TCP came back with -> roll up
 *
 * The TCP call happens OUTSIDE any transaction, and a failure rejects the whole
 * request rather than leaving a local row TCP has never heard of. That
 * no-orphans rule is the entire point: the reason a manager's correction used to
 * be silently reverted an hour later was that it only ever existed here.
 *
 * What this class does NOT do any more is compute a shift's times. Those are
 * recomputed from the segments by ActualShiftRollup, because a shift may sit on
 * more than one segment and TCP owns every one of those times absolutely. This
 * class owns only what TCP has nowhere to put: the verdict, the manager's note,
 * the label, and the link to the plan.
 */
class ActualShiftService
{
    public function __construct(
        private readonly ShiftTimeResolver $times,
        private readonly StoreTimezoneResolver $timezones,
        private readonly ExternalWriteGuard $writeGuard,
        private readonly TcpClientInterface $tcp,
        private readonly TcpJobCodeResolver $jobCodes,
        private readonly TcpWorkSegmentSync $segments,
        private readonly ActualShiftRollup $rollup,
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
     * Routed through upsert() rather than updating the row directly, because an
     * absence has to reach TCP too: the segments are deleted there, and a
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
            'end_time' => substr((string) ($actual->end_time ?? $actual->start_time), 0, 5),
            'label' => $actual->label,
            'shift_type' => $actual->shift_type,
            'review_state' => ActualShift::REVIEW_ABSENT,
            'note' => $note ?? $actual->manager_note,
        ], $userId);
    }

    /**
     * Remove an entry entirely — and the hours behind it.
     *
     * TCP goes first, for the same reason ShiftWriteService deletes Humanity
     * first: a row that vanished from the manager's grid but survived in payroll
     * is the worst divergence available, because nobody is looking at it.
     */
    public function delete(Store $store, ActualShift $actual): void
    {
        $segments = $actual->segments()->get();

        if ($segments->isNotEmpty()) {
            $this->writeGuard->assertAllowed((string) $store->store_number);
        }

        foreach ($segments as $segment) {
            $this->deleteSegment((string) $segment->tcp_work_segment_id);
            $segment->delete();
        }

        $actual->delete();
    }

    /**
     * Create or amend an entry.
     *
     * `time_variance` is DERIVED and never accepted from a client — letting a
     * caller assert how an entry compares to its plan is how the two drift
     * apart. `review_state` is the opposite: only an explicit action sets it,
     * and nothing infers it.
     *
     * Pass `id` to amend a SPECIFIC entry. Ad-hoc coverage has no planned
     * assignment behind it, so the assignment key cannot identify it and every
     * edit would otherwise fall through to create — one extra row per save.
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

        $employee = $this->resolveEmployee($store, (int) $data['employee_id']);

        if ($reviewState === ActualShift::REVIEW_ABSENT) {
            return $this->recordAbsence($store, $data, $time, $assignment, $existing, $userId);
        }

        // TCP first, outside any transaction. Anything that fails here throws,
        // so no local row is left claiming hours TCP does not have.
        $written = $this->pushToTcp($store, $employee, $data, $time, $existing);

        foreach ($written as $dto) {
            $this->segments->syncOne(
                $store,
                $employee,
                $dto,
                // A hand-entry is the one segment TCP can never identify later
                // as ours, so it is labelled at the moment it is created.
                $existing === null ? WorkSegmentRow::ORIGIN_MANUAL : WorkSegmentRow::ORIGIN_DISCOVERED,
                // Grouped once below, after every segment has landed.
                rebuild: false,
            );
        }

        $writtenIds = array_values(array_filter(array_map(fn (TcpWorkSegment $s) => $s->id, $written)));

        /*
         | Keep the manager on the entry they are editing.
         |
         | When TCP has lost the segment, writeSegment() recreates it under a new
         | id — and a new id looks like a brand-new punch to the grouper, which
         | would open a second roll-up and leave the one being edited holding
         | nothing. Attaching it up front says what actually happened: the same
         | shift, re-stated to a vendor that had dropped it.
         */
        if ($existing !== null && $writtenIds !== []) {
            WorkSegmentRow::query()
                ->whereIn('tcp_work_segment_id', $writtenIds)
                ->update(['actual_shift_id' => $existing->id]);
        }

        $this->rollup->rebuild($store, $employee, $time->startsAtUtc);

        $rollup = $this->locateAfterWrite($store, $employee, $existing, $written, $time);

        return $this->applyReview($rollup, $data, $reviewState, $assignment, $userId);
    }

    /**
     * Make TCP agree with the times the manager just stated.
     *
     * A shift here is continuous expected work, so it normally sits on exactly
     * one segment and this is a straight update of it. When it sits on more —
     * somebody punched out and back in — the stated start belongs to the FIRST
     * segment and the stated end to the LAST, and the punches in between are
     * left alone. Rewriting those would be inventing punches nobody made.
     *
     * @return array<int, TcpWorkSegment> the segments TCP now holds
     */
    private function pushToTcp(
        Store $store,
        Employee $employee,
        array $data,
        ResolvedShiftTime $time,
        ?ActualShift $existing,
    ): array {
        $segments = $existing?->segments()->get() ?? collect();

        // Nothing TCP models has changed, so there is nothing to say to it.
        // This is what makes attaching a clock-in to its planned shift free:
        // that is a LINKING operation in our own table, and re-pushing would
        // replace the employee's real punch — actual punch times, missed-punch
        // flags and all — with a manager-entered copy of itself. It also keeps a
        // very small daily quota for changes that are real.
        if ($segments->isNotEmpty() && $this->matchesStoredSegments($segments, $time, $data)) {
            return [];
        }

        $this->writeGuard->assertAllowed((string) $store->store_number);

        $tcpEmployeeId = $this->requireTcpLink($employee);
        $jobCodeId = $this->jobCodes->resolve($store, $employee, $data['position_label'] ?? null);
        $note = $data['note'] ?? null;

        if ($segments->isEmpty()) {
            return [$this->tcp->createWorkSegment(new TcpWorkSegmentPayload(
                employeeId: $tcpEmployeeId,
                jobCodeId: $jobCodeId,
                timeIn: $time->startsLocal,
                timeOut: $time->endsLocal,
                note: $note,
                // A reviewed entry is, by definition, manager-approved.
                managerApproval: true,
            ))];
        }

        $first = $segments->first();
        $last = $segments->last();
        $written = [];

        $written[] = $this->writeSegment($first, new TcpWorkSegmentPayload(
            employeeId: $tcpEmployeeId,
            jobCodeId: $first->tcp_job_code_id ?: $jobCodeId,
            timeIn: $time->startsLocal,
            // A single-segment shift is the whole shift; otherwise only its own
            // end, which the manager did not touch.
            timeOut: $segments->count() === 1 ? $time->endsLocal : CarbonImmutable::parse($first->time_out),
            note: $note,
            managerApproval: true,
        ));

        if ($segments->count() > 1) {
            $written[] = $this->writeSegment($last, new TcpWorkSegmentPayload(
                employeeId: $tcpEmployeeId,
                jobCodeId: $last->tcp_job_code_id ?: $jobCodeId,
                timeIn: CarbonImmutable::parse($last->time_in),
                timeOut: $time->endsLocal,
                note: $note,
                managerApproval: true,
            ));
        }

        return $written;
    }

    /**
     * Update one segment, recreating it if TCP no longer has it.
     *
     * Someone deleting it in TCP's own UI makes our id stale. Recreating is what
     * the manager asked for either way; failing them over our own bookkeeping
     * would not be.
     */
    private function writeSegment(WorkSegmentRow $row, TcpWorkSegmentPayload $payload): TcpWorkSegment
    {
        try {
            return $this->tcp->updateWorkSegment((string) $row->tcp_work_segment_id, $payload);
        } catch (TcpException $e) {
            // Anything but "it isn't there" is a real failure and must still
            // reject the write.
            if ($e->httpStatus !== 404) {
                throw $e;
            }

            Log::info('TCP work segment vanished upstream; recreating it', [
                'tcp_work_segment_id' => $row->tcp_work_segment_id,
            ]);

            $row->delete();

            return $this->tcp->createWorkSegment($payload);
        }
    }

    /**
     * A no-show is the ABSENCE of worked time.
     *
     * TCP models absence as leave, not as a segment, so the honest write is to
     * remove the segments — and leaving them would keep paying someone who never
     * turned up. The row itself stays, carrying the PLANNED times, because that
     * is the only record that this shift was supposed to happen.
     */
    private function recordAbsence(
        Store $store,
        array $data,
        ResolvedShiftTime $time,
        ?ShiftAssignment $assignment,
        ?ActualShift $existing,
        ?int $userId,
    ): ActualShift {
        $segments = $existing?->segments()->get() ?? collect();

        if ($segments->isNotEmpty()) {
            $this->writeGuard->assertAllowed((string) $store->store_number);

            foreach ($segments as $segment) {
                $this->deleteSegment((string) $segment->tcp_work_segment_id);
                $segment->delete();
            }
        }

        $rollup = $existing ?? new ActualShift();

        $rollup->forceFill($time->toAttributes() + [
            'store_id' => $store->id,
            'employee_id' => $data['employee_id'],
            'shift_assignment_id' => $this->claimableAssignment($assignment, $rollup),
            'label' => $data['label'] ?? $rollup->label,
            'shift_type' => $data['shift_type'] ?? $rollup->shift_type ?? 'custom',
            'is_open' => false,
            // Meaningless for a shift nobody worked; review_state is what any
            // reader looks at.
            'time_variance' => ActualShift::VARIANCE_MATCHES,
            'review_state' => ActualShift::REVIEW_ABSENT,
            // A human has just stated this outright. Nothing to flag.
            'needs_attention' => false,
            'manager_note' => $data['note'] ?? $rollup->manager_note,
            'reviewed_by_user_id' => $userId,
            'reviewed_at' => now(),
        ])->save();

        return $rollup->refresh();
    }

    /**
     * The human-owned fields, applied after the roll-up has recomputed the
     * TCP-owned ones.
     *
     * Deliberately the last word: everything above this line comes from TCP and
     * is recomputed on every pass, and everything here is a person's and is not.
     */
    private function applyReview(
        ActualShift $rollup,
        array $data,
        string $reviewState,
        ?ShiftAssignment $assignment,
        ?int $userId,
    ): ActualShift {
        if (array_key_exists('label', $data)) {
            $rollup->label = $data['label'];
        }

        if (isset($data['shift_type'])) {
            $rollup->shift_type = $data['shift_type'];
        }

        // An assignment the client named outright beats the grouper's overlap
        // guess — the manager is saying which plan this shift fulfils.
        if ($assignment !== null) {
            $rollup->shift_assignment_id = $this->claimableAssignment($assignment, $rollup);
        }

        $rollup->review_state = $reviewState;
        $rollup->manager_note = $data['note'] ?? $rollup->manager_note;
        $rollup->reviewed_by_user_id = $userId;
        $rollup->reviewed_at = now();

        // The label may have just changed, and the label is part of the
        // comparison — so this is re-derived rather than left as the roll-up
        // computed it a moment ago.
        $rollup->time_variance = $this->rollup->deriveVariance(
            $assignment ?? $rollup->assignment,
            substr((string) $rollup->start_time, 0, 5),
            $rollup->end_time === null ? null : substr((string) $rollup->end_time, 0, 5),
            $rollup->label
        );

        // Somebody has just looked at this shift, which is what the flag was
        // asking for.
        $rollup->needs_attention = false;

        $rollup->save();

        return $rollup->refresh();
    }

    /**
     * The roll-up the segments we just wrote now belong to.
     *
     * syncOne() rebuilds the grouping as each segment lands, so by this point
     * the roll-up exists; this only has to find it.
     *
     * @param  array<int, TcpWorkSegment>  $written
     */
    private function locateAfterWrite(
        Store $store,
        Employee $employee,
        ?ActualShift $existing,
        array $written,
        ResolvedShiftTime $time,
    ): ActualShift {
        if ($existing !== null) {
            return $existing->refresh();
        }

        $ids = array_values(array_filter(array_map(fn (TcpWorkSegment $s) => $s->id, $written)));

        $rollupId = WorkSegmentRow::query()
            ->whereIn('tcp_work_segment_id', $ids)
            ->whereNotNull('actual_shift_id')
            ->value('actual_shift_id');

        if ($rollupId !== null && ($found = ActualShift::find($rollupId)) !== null) {
            return $found;
        }

        // TCP accepted the write but we could not resolve the segment back to a
        // roll-up — a backfill miss, or an id TCP declined to echo. Rebuilding
        // from the window is the honest recovery, and cheap, because it reads
        // only local rows.
        $this->rollup->rebuild($store, $employee, $time->startsAtUtc);

        $rollupId = WorkSegmentRow::query()
            ->whereIn('tcp_work_segment_id', $ids)
            ->value('actual_shift_id');

        return ($rollupId !== null ? ActualShift::find($rollupId) : null)
            ?? ActualShift::query()->create($time->toAttributes() + [
                'store_id' => $store->id,
                'employee_id' => $employee->id,
                'review_state' => ActualShift::REVIEW_UNREVIEWED,
                'time_variance' => ActualShift::VARIANCE_UNPLANNED,
            ]);
    }

    /**
     * Whether TCP already holds exactly what this write would send.
     *
     * Only the fields TCP actually stores count — the span and the note. Label,
     * the verdict and the planned link are ours alone, so changing one of those
     * is not a reason to spend a call.
     *
     * @param  Collection<int, WorkSegmentRow>  $segments
     */
    private function matchesStoredSegments(Collection $segments, ResolvedShiftTime $time, array $data): bool
    {
        $first = $segments->first();
        $last = $segments->last();

        // An open segment has no end to compare, and a manager stating an end
        // time for a shift still running is a real change.
        if ($last->ends_at_utc === null) {
            return false;
        }

        $sameSpan = $first->starts_at_utc?->equalTo($time->startsAtUtc)
            && $last->ends_at_utc->equalTo($time->endsAtUtc);

        $sameNote = trim((string) ($data['note'] ?? '')) === trim((string) $first->segment_note);

        return (bool) $sameSpan && $sameNote;
    }

    /** A segment that is already gone is the outcome we wanted. */
    private function deleteSegment(string $segmentId): void
    {
        if ($segmentId === '') {
            return;
        }

        try {
            $this->tcp->deleteWorkSegment($segmentId);
        } catch (TcpException $e) {
            if ($e->httpStatus !== 404) {
                throw $e;
            }
        }
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

    /** One actual per planned assignment, and the column is unique. */
    private function claimableAssignment(?ShiftAssignment $assignment, ActualShift $rollup): ?int
    {
        if ($assignment === null) {
            return $rollup->shift_assignment_id;
        }

        $holder = ActualShift::query()
            ->where('shift_assignment_id', $assignment->id)
            ->when($rollup->exists, fn ($q) => $q->where('id', '!=', $rollup->id))
            ->first();

        return $holder === null ? (int) $assignment->id : $rollup->shift_assignment_id;
    }

    /**
     * The row this write belongs to, in order of how strongly each key
     * identifies one:
     *
     *   `id`         the manager is editing THIS entry — the only key ad-hoc
     *                coverage has, since it has no planned counterpart;
     *   assignment   one actual per planned assignment, so re-reviewing a shift
     *                amends rather than stacking duplicates;
     *   otherwise    nothing matches yet, and this is a new entry.
     */
    private function locateExisting(
        Store $store,
        array $data,
        ?ShiftAssignment $assignment,
        ResolvedShiftTime $time,
    ): ?ActualShift {
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
     *  1. An explicit `review_state` wins. That is a deliberate action, and it
     *     is the ONLY way out of an absence.
     *  2. An existing absence is preserved. Correcting the note on a no-show is
     *     not a statement about attendance, and silently promoting it to
     *     `worked` is exactly the bug that let an edit record someone as having
     *     worked a shift they never turned up for.
     *  3. Otherwise `worked`. Anything else reaching here is a person recording
     *     or amending worked time — including a manager amending a clock-in that
     *     arrived `unreviewed`, which is precisely the act of reviewing it.
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

    public function present(ActualShift $actual, ?int $dayIndex = null): array
    {
        $actual->loadMissing('segments');

        return [
            'id' => (string) $actual->id,
            'employee_id' => (string) $actual->employee_id,
            'planned_shift_id' => $actual->shift_assignment_id === null ? null : (string) $actual->shift_assignment_id,
            'shift_date' => $actual->shift_date?->toDateString(),
            'day_index' => $dayIndex,
            'start_time' => substr((string) $actual->start_time, 0, 5),
            // Null while the shift is still being worked. Readers branch on
            // is_open rather than guessing from a missing value.
            'end_time' => $actual->end_time === null ? null : substr((string) $actual->end_time, 0, 5),
            'duration_minutes' => (int) $actual->duration_minutes,
            'is_open' => (bool) $actual->is_open,
            'label' => $actual->label,
            'type' => $actual->shift_type,
            'time_variance' => $actual->time_variance,
            'review_state' => $actual->review_state,
            'needs_attention' => (bool) $actual->needs_attention,
            'grouping_pinned' => (bool) $actual->grouping_pinned,
            'note' => $actual->manager_note,
            'source' => $actual->source,
            // The punches behind this shift. Normally one — a shift is
            // continuous expected work — but a manager needs to see all of them
            // when somebody punched out and back in.
            'segments' => $actual->segments->map(fn (WorkSegmentRow $s) => [
                'id' => (string) $s->id,
                'work_segment_id' => $s->tcp_work_segment_id,
                'time_in' => $s->time_in?->toDateTimeString(),
                'time_out' => $s->time_out?->toDateTimeString(),
                'duration_minutes' => $s->duration_minutes,
                'is_open' => $s->isOpen(),
                'has_missed_punch' => $s->hasMissedPunch(),
                'origin' => $s->origin,
                'note' => $s->segment_note,
            ])->values(),
        ];
    }
}
