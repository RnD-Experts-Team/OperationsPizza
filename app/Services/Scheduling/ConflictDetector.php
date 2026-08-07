<?php

namespace App\Services\Scheduling;

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Services\Scheduling\Dto\ResolvedShiftTime;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Server-side mirror of the frontend's detectConflicts(), but operating on UTC
 * instants rather than "HH:MM" strings.
 *
 * That difference is not cosmetic: the frontend compares wall-clock minutes, so
 * it cannot see a 22:00–02:00 shift colliding with the next morning's
 * 01:00–09:00 one. Overnight shifts are normal here, so the backend has to be
 * the authority.
 */
class ConflictDetector
{
    /**
     * Existing assignments for this employee that overlap the window.
     *
     * @return Collection<int, ShiftAssignment>
     */
    public function conflictsFor(
        int $employeeId,
        DateTimeInterface $startsAtUtc,
        DateTimeInterface $endsAtUtc,
        ?int $ignoreShiftId = null,
    ): Collection {
        return ShiftAssignment::query()
            ->with('shift')
            ->where('employee_id', $employeeId)
            ->whereHas('shift', function ($query) use ($startsAtUtc, $endsAtUtc, $ignoreShiftId) {
                $query->overlapping($startsAtUtc, $endsAtUtc);

                if ($ignoreShiftId !== null) {
                    $query->where('id', '!=', $ignoreShiftId);
                }
            })
            ->get();
    }

    public function hasConflict(
        int $employeeId,
        ResolvedShiftTime $time,
        ?int $ignoreShiftId = null,
    ): bool {
        return $this->conflictsFor($employeeId, $time->startsAtUtc, $time->endsAtUtc, $ignoreShiftId)->isNotEmpty();
    }

    /** Shaped for the SHIFT_CONFLICT error body. */
    public function describe(Collection $assignments): array
    {
        return $assignments->map(fn (ShiftAssignment $assignment) => [
            'shift_id' => $assignment->shift_id,
            'assignment_id' => $assignment->id,
            'shift_date' => $assignment->shift?->shift_date?->toDateString(),
            'start_time' => $this->hhmm($assignment->shift?->start_time),
            'end_time' => $this->hhmm($assignment->shift?->end_time),
            'label' => $assignment->shift?->label,
        ])->values()->all();
    }

    /**
     * Every pair of overlapping assignments in a set, for the week payload's
     * `conflicts` array. Pairs are emitted once, not twice.
     *
     * @param  Collection<int, ShiftAssignment>  $assignments  with `shift` loaded
     */
    public function pairsIn(Collection $assignments): array
    {
        $byEmployee = $assignments->groupBy('employee_id');
        $pairs = [];

        foreach ($byEmployee as $employeeId => $group) {
            $rows = $group->values();

            for ($i = 0; $i < $rows->count(); $i++) {
                for ($j = $i + 1; $j < $rows->count(); $j++) {
                    $a = $rows[$i]->shift;
                    $b = $rows[$j]->shift;

                    if ($a === null || $b === null) {
                        continue;
                    }

                    if ($a->starts_at_utc < $b->ends_at_utc && $a->ends_at_utc > $b->starts_at_utc) {
                        $pairs[] = [
                            'employee_id' => (string) $employeeId,
                            'shift_a_id' => (string) $rows[$i]->id,
                            'shift_b_id' => (string) $rows[$j]->id,
                            'shift_date' => $a->shift_date?->toDateString(),
                        ];
                    }
                }
            }
        }

        return $pairs;
    }

    private function hhmm(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }

    /** Shifts overlapping a window regardless of employee — used by coverage views. */
    public function shiftsOverlapping(int $storeId, DateTimeInterface $from, DateTimeInterface $to): Collection
    {
        return Shift::query()
            ->forStore($storeId)
            ->overlapping($from, $to)
            ->get();
    }
}
