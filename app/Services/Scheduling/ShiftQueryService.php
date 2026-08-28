<?php

namespace App\Services\Scheduling;

use App\Models\ShiftAssignment;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Reads the mirror and shapes it for the grid.
 *
 * The API is assignment-oriented, not shift-oriented: a Humanity shift can hold
 * several employees, but the grid draws one card per employee, so each
 * assignment becomes one ShiftDTO whose `id` is the assignment id.
 */
class ShiftQueryService
{
    public function __construct(
        private readonly WeekResolver $weeks,
    ) {
    }

    /** @return Collection<int, ShiftAssignment> */
    public function assignmentsForRange(Store $store, string $from, string $to): Collection
    {
        // Ordered by day, not by insertion. Bulk work derived from this (copy
        // week, template capture) is written to Humanity in this order, and
        // when a throttle cuts a run short the schedule must degrade from the
        // END of the week — losing Sunday is survivable, losing Monday is not.
        return ShiftAssignment::query()
            ->with(['shift', 'employee'])
            ->whereHas('shift', fn ($query) => $query
                ->forStore((int) $store->id)
                ->inDateRange($from, $to))
            ->join('shifts', 'shifts.id', '=', 'shift_assignments.shift_id')
            ->orderBy('shifts.shift_date')
            ->orderBy('shifts.start_time')
            ->select('shift_assignments.*')
            ->get();
    }

    /**
     * @param  Collection<int, ShiftAssignment>  $assignments
     * @return array<int, array<string, mixed>>
     */
    public function toDtos(Collection $assignments, CarbonImmutable $weekStart, array $departmentByPositionId = []): array
    {
        return $assignments
            ->filter(fn (ShiftAssignment $assignment) => $assignment->shift !== null)
            ->map(function (ShiftAssignment $assignment) use ($weekStart, $departmentByPositionId) {
                $shift = $assignment->shift;

                return [
                    // The assignment id, because the grid's unit is one
                    // employee's card, not the Humanity shift.
                    'id' => (string) $assignment->id,
                    'shift_id' => (int) $shift->id,
                    'humanity_shift_id' => $shift->humanity_shift_id,
                    'employee_id' => (string) $assignment->employee_id,
                    'shift_date' => $shift->shift_date?->toDateString(),
                    // Computed per request; never stored.
                    'day_index' => $this->weeks->dayIndexFor(
                        CarbonImmutable::parse($shift->shift_date),
                        $weekStart
                    ),
                    'start_time' => substr((string) $shift->start_time, 0, 5),
                    'end_time' => substr((string) $shift->end_time, 0, 5),
                    'starts_at' => $shift->starts_at_utc?->toIso8601String(),
                    'ends_at' => $shift->ends_at_utc?->toIso8601String(),
                    // The frontend must prefer this over recomputing hours from
                    // start/end: on DST days wall-clock arithmetic is wrong, and
                    // this is the payroll-facing number.
                    'duration_minutes' => (int) $shift->duration_minutes,
                    'crosses_midnight' => (bool) $shift->crosses_midnight,
                    'label' => $shift->label,
                    'type' => $shift->shift_type,
                    'note' => $shift->note,
                    'is_recurring' => $shift->recurring_group_id !== null,
                    'recurring_group_id' => $shift->recurring_group_id,
                    'is_published' => (bool) $shift->is_published,
                    // synced | pending | parked. Anything but `synced` means
                    // the shift exists here but not yet in Humanity, so staff
                    // cannot see it — worth surfacing rather than leaving the
                    // manager to infer it from a null humanity_shift_id.
                    'sync_status' => $shift->sync_status,
                    'department' => $departmentByPositionId[$shift->humanity_position_id] ?? null,
                    'origin' => $shift->origin,
                    'updated_at' => $shift->updated_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Stats strip. Hours come from duration_minutes, never from the wall clock.
     *
     * @param  array<int, array<string, mixed>>  $shiftDtos
     */
    public function stats(array $shiftDtos, int $activeEmployees, float $laborCost): array
    {
        $totalMinutes = array_sum(array_column($shiftDtos, 'duration_minutes'));

        return [
            'total_hours' => round($totalMinutes / 60, 2),
            'total_shifts' => count($shiftDtos),
            'active_employees' => $activeEmployees,
            'labor_cost' => $laborCost,
        ];
    }

    /**
     * Employees over the weekly threshold, by minutes worked.
     *
     * @param  array<int, array<string, mixed>>  $shiftDtos
     * @return array<int, string>
     */
    public function overtimeEmployeeIds(array $shiftDtos, int $thresholdMinutes): array
    {
        $byEmployee = [];

        foreach ($shiftDtos as $dto) {
            $employeeId = (string) $dto['employee_id'];
            $byEmployee[$employeeId] = ($byEmployee[$employeeId] ?? 0) + (int) $dto['duration_minutes'];
        }

        return array_values(array_keys(array_filter(
            $byEmployee,
            fn (int $minutes) => $minutes > $thresholdMinutes
        )));
    }

}
