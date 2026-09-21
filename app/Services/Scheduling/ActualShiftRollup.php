<?php

namespace App\Services\Scheduling;

use App\Models\ActualShift;
use App\Models\Employee;
use App\Models\ShiftAssignment;
use App\Models\Store;
use App\Models\TcpWorkSegment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Groups punched segments into the shifts a manager actually reviews.
 *
 * TCP stores segments. Punching out and back in CLOSES one and OPENS another,
 * so a single shift routinely arrives as several — which is why `actual_shifts`
 * being 1:1 with a segment could not work: overlap-matching mapped every segment
 * of a split shift onto the same planned assignment, and that column is unique,
 * so the second one could not be written at all.
 *
 * What decides that two segments are the same shift is a gap threshold. It is
 * NOT a break rule — a shift here is continuous expected work, and breaks are
 * not something the business schedules. The threshold exists to glue punch noise
 * back together while leaving a genuine split shift ("come in this morning, then
 * again this evening") as the two separate shifts it really is.
 *
 * Ownership, which is the whole reason this is safe to run on a schedule:
 *
 *   TCP owns   every time, duration and punch. All of it is recomputed FROM the
 *              segments on every pass and never locally overridden.
 *   We own     the grouping, the link to the plan, and the human verdict. None
 *              of those exist in TCP, so honouring them contradicts nothing.
 *
 * So this recomputes `time_variance` and `needs_attention` freely, and never
 * touches `review_state`. A roll-up a manager has pinned keeps its segments
 * exactly as they left them — only its numbers are refreshed.
 */
class ActualShiftRollup
{
    public function __construct(
        private readonly StoreTimezoneResolver $timezones,
    ) {
    }

    /**
     * Rebuild every roll-up touching a window, for one employee.
     *
     * @return array{rollups:int, segments:int}
     */
    public function rebuild(Store $store, Employee $employee, CarbonImmutable $around): array
    {
        // Wide enough that a group cannot be cut in half by the window edge: a
        // shift may run the full cap, and a gap may sit at either end of it.
        $padHours = (int) config('tcp.rollup.max_shift_hours', 16)
            + (int) ceil($this->gapMinutes() / 60)
            + 1;

        $from = $around->subHours($padHours);
        $to = $around->addHours($padHours);

        $segments = TcpWorkSegment::query()
            ->where('employee_id', $employee->id)
            ->where('store_id', $store->id)
            ->overlapping($from, $to)
            ->orderBy('starts_at_utc')
            ->get();

        /*
         | Roll-ups these segments belonged to BEFORE this pass. Anything left
         | holding nothing afterwards has to be dealt with rather than orphaned.
         |
         | Deliberately includes RETIRED segments. A shift whose every punch was
         | voided in TCP is precisely the case that needs settling, and it is
         | also the one the live query above cannot see — leaving it to be found
         | from the surviving segments would mean it is never found at all.
         */
        $previousRollupIds = TcpWorkSegment::query()
            ->withTrashed()
            ->where('employee_id', $employee->id)
            ->where('store_id', $store->id)
            ->overlapping($from, $to)
            ->whereNotNull('actual_shift_id')
            ->distinct()
            ->pluck('actual_shift_id');

        $pinned = ActualShift::query()
            ->whereIn('id', $previousRollupIds)
            ->where('grouping_pinned', true)
            ->pluck('id');

        $timezone = $this->timezones->for($store);
        $rebuilt = 0;

        // A pinned roll-up's membership is a human's decision; only its numbers
        // are refreshed from TCP.
        foreach ($pinned as $rollupId) {
            $group = $segments->where('actual_shift_id', $rollupId)->values();

            if ($group->isNotEmpty() && ($rollup = ActualShift::find($rollupId)) !== null) {
                $this->apply($rollup, $group, $timezone);
                $rebuilt++;
            }
        }

        $free = $segments->reject(fn (TcpWorkSegment $s) => $pinned->contains($s->actual_shift_id))->values();

        foreach ($this->groupByGap($free) as $group) {
            $this->materialise($store, $employee, $group, $timezone);
            $rebuilt++;
        }

        $this->settleEmptied($previousRollupIds);

        return ['rollups' => $rebuilt, 'segments' => $segments->count()];
    }

    /**
     * Fold other shifts' punches into this one and pin the result.
     *
     * The gap rule is a default, not a verdict. Somebody who punched out for
     * forty minutes and back in worked one shift, however TCP recorded it, and
     * only a person can say so.
     *
     * Pinning is safe because grouping is OURS — TCP has no notion of which
     * segments form a shift, so this contradicts nothing it holds. The times
     * underneath are still recomputed from TCP and are never overridden here.
     *
     * @param  array<int, int>  $otherIds
     */
    public function merge(Store $store, ActualShift $target, array $otherIds): ActualShift
    {
        $others = ActualShift::query()
            ->where('store_id', $store->id)
            ->where('employee_id', $target->employee_id)
            ->whereIn('id', $otherIds)
            ->where('id', '!=', $target->id)
            ->get();

        foreach ($others as $other) {
            TcpWorkSegment::query()
                ->where('actual_shift_id', $other->id)
                ->update(['actual_shift_id' => $target->id]);

            // The merged-away row keeps no hours, so it must not linger in the
            // grid claiming any.
            $other->delete();
        }

        $target->grouping_pinned = true;
        $target->save();

        return $this->recompute($store, $target);
    }

    /**
     * Break punches out of this shift into one of their own, and pin both.
     *
     * The mirror of merge(): two shifts the gap rule glued together because the
     * punches happened to be close, which a manager knows were separate.
     *
     * @param  array<int, int>  $segmentIds
     */
    public function split(Store $store, ActualShift $source, array $segmentIds): ActualShift
    {
        $moving = TcpWorkSegment::query()
            ->where('actual_shift_id', $source->id)
            ->whereIn('id', $segmentIds)
            ->orderBy('starts_at_utc')
            ->get();

        if ($moving->isEmpty() || $moving->count() === $source->segments()->count()) {
            throw new Exceptions\SchedulingException(
                'A split needs at least one segment to move and at least one to leave behind.',
                'INVALID_SPLIT',
                422,
                ['actual_shift_id' => (string) $source->id]
            );
        }

        $created = ActualShift::query()->create([
            'store_id' => $store->id,
            'employee_id' => $source->employee_id,
            'shift_type' => $source->shift_type ?? 'custom',
            // A fresh shift nobody has judged. The verdict on the shift it came
            // out of says nothing about this one.
            'review_state' => ActualShift::REVIEW_UNREVIEWED,
            'time_variance' => ActualShift::VARIANCE_UNPLANNED,
            'grouping_pinned' => true,
            // Provisional; recompute() replaces all of it from the segments.
            'shift_date' => $moving->first()->time_in->toDateString(),
            'start_time' => $moving->first()->time_in->format('H:i:s'),
            'starts_at_utc' => $moving->first()->starts_at_utc,
            'duration_minutes' => 0,
        ]);

        TcpWorkSegment::query()
            ->whereIn('id', $moving->pluck('id'))
            ->update(['actual_shift_id' => $created->id]);

        $source->grouping_pinned = true;
        $source->save();

        $this->recompute($store, $source);

        return $this->recompute($store, $created);
    }

    /** Refresh one roll-up's numbers from the segments it currently holds. */
    private function recompute(Store $store, ActualShift $rollup): ActualShift
    {
        $group = $rollup->segments()->get();

        if ($group->isEmpty()) {
            $this->settleEmptied(collect([$rollup->id]));

            return $rollup->refresh();
        }

        $this->apply($rollup, $group, $this->timezones->for($store));

        return $rollup->refresh();
    }

    /**
     * Split a chronological run of segments wherever the gap is too wide.
     *
     * An open segment has no end, so nothing can follow it in the same group —
     * it is by definition the last thing that happened.
     *
     * @param  Collection<int, TcpWorkSegment>  $segments
     * @return array<int, Collection<int, TcpWorkSegment>>
     */
    private function groupByGap(Collection $segments): array
    {
        $gapSeconds = $this->gapMinutes() * 60;
        $groups = [];
        $current = [];
        $previousEnd = null;

        foreach ($segments as $segment) {
            $tooFar = $previousEnd === null
                || $segment->starts_at_utc->getTimestamp() - $previousEnd > $gapSeconds;

            if ($current !== [] && $tooFar) {
                $groups[] = collect($current);
                $current = [];
            }

            $current[] = $segment;
            $previousEnd = $segment->ends_at_utc?->getTimestamp();

            // Open: nothing can join after it.
            if ($previousEnd === null) {
                $groups[] = collect($current);
                $current = [];
            }
        }

        if ($current !== []) {
            $groups[] = collect($current);
        }

        return $groups;
    }

    /**
     * Find or create the roll-up for a group, then point its segments at it.
     *
     * Reuses a roll-up one of these segments already belonged to, so ids stay
     * stable across passes and a manager's review does not detach itself the
     * first time somebody adds a punch to the night.
     *
     * @param  Collection<int, TcpWorkSegment>  $group
     */
    private function materialise(Store $store, Employee $employee, Collection $group, string $timezone): void
    {
        $assignment = $this->matchPlannedAssignment($employee, $group);

        $rollup = $this->existingFor($group, $assignment)
            ?? new ActualShift([
                'store_id' => $store->id,
                'employee_id' => $employee->id,
                'shift_type' => 'custom',
                'review_state' => ActualShift::REVIEW_UNREVIEWED,
            ]);

        $rollup->store_id = $store->id;
        $rollup->employee_id = $employee->id;

        /*
         | A shift fulfilling a plan takes the plan's name, unless a manager has
         | given it one of its own.
         |
         | Segments carry no label — TCP has nowhere to put one — so without this
         | every synced shift would compare its empty label against a named plan,
         | come out `differs`, and raise needs_attention. That would flag the
         | entire roster nightly and make the flag worthless.
         */
        if (blank($rollup->label) && filled($assignment?->shift?->label)) {
            $rollup->label = $assignment->shift->label;
        }

        /*
         | One actual per planned assignment, and the column is unique — so a
         | group may only claim an assignment no other roll-up already holds.
         |
         | Losing the link is the right failure: it makes the shift `unplanned`,
         | which is visible and correctable, whereas letting the write through
         | would abort the whole sync run and lose the hours entirely. That was
         | the original bug.
         */
        $rollup->shift_assignment_id = $this->claimable($assignment, $rollup) ? $assignment?->id : null;

        $this->apply($rollup, $group, $timezone, $assignment);

        TcpWorkSegment::query()
            ->whereIn('id', $group->pluck('id'))
            ->update(['actual_shift_id' => $rollup->id]);
    }

    /**
     * Recompute everything TCP owns, and the two flags derived from it.
     *
     * Never writes `review_state` or `grouping_pinned`: both are human
     * assertions, and this runs unattended.
     *
     * @param  Collection<int, TcpWorkSegment>  $group
     */
    private function apply(ActualShift $rollup, Collection $group, string $timezone, ?ShiftAssignment $assignment = null): void
    {
        $assignment ??= $rollup->assignment;

        $first = $group->first();
        $open = $group->first(fn (TcpWorkSegment $s) => $s->isOpen());
        $lastClosed = $group->last(fn (TcpWorkSegment $s) => !$s->isOpen());

        $startsUtc = CarbonImmutable::parse($first->starts_at_utc);
        $startsLocal = $startsUtc->setTimezone($timezone);

        $endsUtc = $open !== null ? null : CarbonImmutable::parse($lastClosed->ends_at_utc);
        $endsLocal = $endsUtc?->setTimezone($timezone);

        // Sum of what was actually worked, not the span — a gap between two
        // segments is time off the clock and nobody is paid for it.
        $worked = (int) $group->sum(fn (TcpWorkSegment $s) => (int) $s->duration_minutes);

        $staleOpen = false;

        if ($open !== null) {
            $capMinutes = (int) config('tcp.rollup.max_shift_hours', 16) * 60;
            $elapsed = (int) round((CarbonImmutable::now()->getTimestamp() - $open->starts_at_utc->getTimestamp()) / 60);

            // Somebody forgot to clock out. Stop counting rather than show a
            // forty-hour shift — and never invent an end time, because only a
            // real punch or a correction in TCP can close the segment.
            $staleOpen = $elapsed > $capMinutes;
            $worked += max(0, min($elapsed, $capMinutes));
        }

        $rollup->shift_date = $startsLocal->toDateString();
        $rollup->start_time = $startsLocal->format('H:i:s');
        $rollup->end_time = $endsLocal?->format('H:i:s');
        $rollup->starts_at_utc = $startsUtc->toDateTimeString();
        $rollup->ends_at_utc = $endsUtc?->toDateTimeString();
        $rollup->duration_minutes = $worked;
        $rollup->crosses_midnight = $endsLocal !== null && $endsLocal->toDateString() !== $startsLocal->toDateString();
        $rollup->is_open = $open !== null;

        $rollup->time_variance = $this->deriveVariance(
            $assignment,
            $startsLocal->format('H:i'),
            $endsLocal?->format('H:i'),
            $rollup->label
        );

        $rollup->needs_attention = $this->deriveNeedsAttention($rollup, $group, $staleOpen);

        $rollup->save();
    }

    /**
     * What the grid should put in front of a manager.
     *
     * DERIVED, and it is what lets `review_state` stay purely asserted: a clean
     * shift raises nothing, so nobody has to click through it just to record
     * that it was fine.
     *
     * @param  Collection<int, TcpWorkSegment>  $group
     */
    private function deriveNeedsAttention(ActualShift $rollup, Collection $group, bool $staleOpen): bool
    {
        if ($staleOpen) {
            return true;
        }

        // TCP's own incompleteness flag: the recorded time is a system default,
        // not something the employee did.
        if ($group->contains(fn (TcpWorkSegment $s) => $s->hasMissedPunch())) {
            return true;
        }

        $reviewed = $rollup->review_state !== ActualShift::REVIEW_UNREVIEWED
            && $rollup->reviewed_at !== null;

        if ($reviewed) {
            // The hours moved after somebody signed them off. Their verdict is
            // preserved — it is never ours to overwrite — but it now covers
            // different hours than the ones they looked at, so it resurfaces.
            return $group->contains(
                fn (TcpWorkSegment $s) => $s->updated_at?->greaterThan($rollup->reviewed_at) ?? false
            );
        }

        // An unreviewed shift that is still running is not yet a problem; its
        // variance cannot be judged until it ends.
        if ($rollup->is_open) {
            return false;
        }

        return $rollup->time_variance !== ActualShift::VARIANCE_MATCHES;
    }

    /**
     * How a worked shift compares to the plan behind it.
     *
     * THE shared rule, used by both this and ActualShiftService. They used to
     * have one each — this one compared times only, the review path compared
     * times and label — so a synced row (whose label is always null) was
     * reported as `matches` against a labelled plan, while any manager edit to
     * the same row flipped it to `differs`. The comment claiming they could not
     * disagree was simply wrong.
     *
     * A shift still in progress has no end to compare, so it is judged on what
     * is known so far and settles when it closes.
     */
    public function deriveVariance(?ShiftAssignment $assignment, string $startHi, ?string $endHi, ?string $label): string
    {
        if ($assignment?->shift === null) {
            return ActualShift::VARIANCE_UNPLANNED;
        }

        $shift = $assignment->shift;

        $sameStart = substr((string) $shift->start_time, 0, 5) === $startHi;
        $sameEnd = $endHi === null || substr((string) $shift->end_time, 0, 5) === $endHi;

        // The plan includes what the shift was CALLED. An empty label and no
        // label are the same thing to a manager.
        $sameLabel = trim((string) $label) === trim((string) $shift->label);

        return $sameStart && $sameEnd && $sameLabel
            ? ActualShift::VARIANCE_MATCHES
            : ActualShift::VARIANCE_DIFFERS;
    }

    /**
     * The planned assignment this group most likely fulfils.
     *
     * Overlap rather than exact match: people clock in early and leave late,
     * which is the entire point of comparing planned against actual. An open
     * group is measured to now, so an in-progress shift still finds its plan.
     *
     * @param  Collection<int, TcpWorkSegment>  $group
     */
    private function matchPlannedAssignment(Employee $employee, Collection $group): ?ShiftAssignment
    {
        $startsUtc = CarbonImmutable::parse($group->first()->starts_at_utc);

        $endsUtc = $group
            ->map(fn (TcpWorkSegment $s) => $s->ends_at_utc)
            ->filter()
            ->max();

        $endsUtc = $endsUtc === null
            ? CarbonImmutable::now()
            : CarbonImmutable::parse($endsUtc);

        return ShiftAssignment::query()
            ->with('shift')
            ->where('employee_id', $employee->id)
            ->whereHas('shift', fn ($query) => $query->overlapping($startsUtc, $endsUtc))
            ->get()
            // Nearest start wins when a split shift produces two candidates.
            ->sortBy(fn (ShiftAssignment $a) => abs(
                ($a->shift?->starts_at_utc?->getTimestamp() ?? 0) - $startsUtc->getTimestamp()
            ))
            ->first();
    }

    /**
     * The roll-up this group already belongs to, if any.
     *
     * @param  Collection<int, TcpWorkSegment>  $group
     */
    private function existingFor(Collection $group, ?ShiftAssignment $assignment): ?ActualShift
    {
        $existingId = $group->pluck('actual_shift_id')->filter()->first();

        if ($existingId !== null && ($found = ActualShift::find($existingId)) !== null) {
            return $found;
        }

        if ($assignment !== null) {
            return ActualShift::query()->where('shift_assignment_id', $assignment->id)->first();
        }

        return null;
    }

    /** Whether this roll-up may take the assignment, or somebody else holds it. */
    private function claimable(?ShiftAssignment $assignment, ActualShift $rollup): bool
    {
        if ($assignment === null) {
            return false;
        }

        $holder = ActualShift::query()
            ->where('shift_assignment_id', $assignment->id)
            ->when($rollup->exists, fn ($q) => $q->where('id', '!=', $rollup->id))
            ->first();

        return $holder === null;
    }

    /**
     * Roll-ups that came out of this pass holding no segments at all.
     *
     * Three different situations, and conflating them is how hours or verdicts
     * get lost:
     *
     *   an absence          legitimately has no segments — marking one DELETES
     *                       the segment in TCP, because TCP has no way to say
     *                       "did not work". Left alone.
     *   reviewed, now empty a human signed off hours TCP no longer has. Their
     *                       verdict is never silently discarded, so the row
     *                       stays at zero and raises needs_attention.
     *   unreviewed, empty   nobody ever looked, and TCP says it did not happen.
     *                       Soft-deleted.
     *
     * @param  Collection<int, int>  $rollupIds
     */
    private function settleEmptied(Collection $rollupIds): void
    {
        if ($rollupIds->isEmpty()) {
            return;
        }

        $empty = ActualShift::query()
            ->whereIn('id', $rollupIds)
            ->where('review_state', '!=', ActualShift::REVIEW_ABSENT)
            ->whereDoesntHave('segments')
            ->get();

        foreach ($empty as $rollup) {
            if ($rollup->review_state === ActualShift::REVIEW_UNREVIEWED) {
                $rollup->delete();

                continue;
            }

            $rollup->forceFill([
                'duration_minutes' => 0,
                'is_open' => false,
                'needs_attention' => true,
            ])->save();
        }
    }

    private function gapMinutes(): int
    {
        return max(1, (int) config('tcp.rollup.gap_minutes', 60));
    }
}
