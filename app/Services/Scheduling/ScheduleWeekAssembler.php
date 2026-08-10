<?php

namespace App\Services\Scheduling;

use App\Models\ActualShift;
use App\Models\Employee;
use App\Models\HumanityPosition;
use App\Models\PublishedSchedule;
use App\Models\Store;
use App\Models\TimeOff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the single payload that replaces essentially all of the frontend's
 * mock data in one round trip: roster, shifts, actuals, availability, time off,
 * conflicts, stats and the published record for a week.
 *
 * One call rather than eight because the grid needs all of it before it can
 * draw anything, and eight round trips would show the user a week assembling
 * itself piece by piece.
 */
class ScheduleWeekAssembler
{
    public function __construct(
        private readonly WeekResolver $weeks,
        private readonly ShiftQueryService $shifts,
        private readonly ConflictDetector $conflicts,
        private readonly AvailabilityProjector $availability,
        private readonly LaborCostCalculator $labor,
        private readonly EmployeePresenter $employees,
        private readonly StoreTimezoneResolver $timezones,
    ) {
    }

    /**
     * @param  array{mode?:string, department?:string, search?:string}  $filters
     */
    public function assemble(Store $store, string $weekStartInput, array $filters = []): array
    {
        $settings = $store->settings();
        $weekStartDow = $this->weeks->weekStartDow($settings);
        $weekStart = $this->weeks->normalizeWeekStart($weekStartInput, $weekStartDow);
        $weekEnd = $weekStart->addDays(6);

        $from = $weekStart->toDateString();
        $to = $weekEnd->toDateString();

        $mode = $filters['mode'] ?? 'planned';

        $assignments = $this->shifts->assignmentsForRange($store, $from, $to);
        $departments = $this->departmentNamesByHumanityId($store);
        $shiftDtos = $this->shifts->toDtos($assignments, $weekStart, $departments);

        $roster = $this->roster($store, $assignments->pluck('employee_id')->unique()->all(), $filters);

        $this->labor->preload($roster->pluck('id')->all());

        $employeeDtos = $this->employees->present($roster, $store, $this->labor, $from);
        $employeeIds = array_column($employeeDtos, 'id');

        // Filters apply to the roster; shifts follow it, so a filtered grid
        // never shows a card with no row to sit on.
        $shiftDtos = array_values(array_filter(
            $shiftDtos,
            fn (array $dto) => in_array((string) $dto['employee_id'], $employeeIds, true)
        ));

        $actualDtos = $mode === 'planned'
            ? []
            : $this->actualShiftDtos($store, $from, $to, $weekStart, $employeeIds);

        $laborCost = $this->labor->totalFor(
            collect($shiftDtos)->map(fn (array $dto) => [
                'employee_id' => (int) $dto['employee_id'],
                'shift_date' => $dto['shift_date'],
                'duration_minutes' => (int) $dto['duration_minutes'],
            ]),
            $settings
        );

        $overtimeThresholdMinutes = (int) ($settings->overtime_threshold_minutes ?? 2400);

        return [
            'week' => [
                'start' => $from,
                'end' => $to,
                'label' => $this->weeks->label($weekStart),
                'week_start_dow' => $weekStartDow,
                'day_dates' => array_map(fn (CarbonImmutable $d) => (string) $d->day, $this->weeks->datesForWeek($weekStart)),
                'full_dates' => array_map(fn (CarbonImmutable $d) => $d->toDateString(), $this->weeks->datesForWeek($weekStart)),
            ],
            'store' => [
                'store_number' => $store->store_number,
                'name' => $store->name,
                'timezone' => $this->timezones->for($store),
                'open_time' => substr((string) $settings->open_time, 0, 5),
                'close_time' => substr((string) $settings->close_time, 0, 5),
                'slot_minutes' => (int) $settings->slot_minutes,
                'overtime_threshold_hours' => round($overtimeThresholdMinutes / 60, 2),
                'default_labor_rate' => $settings->default_labor_rate_cents === null
                    ? null
                    : $settings->default_labor_rate_cents / 100,
            ],
            'employees' => $employeeDtos,
            'departments' => $this->employees->departments($store),
            'shifts' => $shiftDtos,
            'actual_shifts' => $actualDtos,
            'availability' => $this->availability->forWeek($store, $roster, $weekStart, $settings),
            'time_off' => $this->timeOffDtos($store, $from, $to, $weekStart, $employeeIds),
            'published' => $this->publishedDto($store, $from),
            'stats' => $this->shifts->stats($shiftDtos, count($employeeDtos), $laborCost),
            'overtime_employee_ids' => $this->shifts->overtimeEmployeeIds($shiftDtos, $overtimeThresholdMinutes),
            'conflicts' => $this->conflicts->pairsIn($assignments),
        ];
    }

    /**
     * Active roster PLUS anyone with a shift this week.
     *
     * Without the union, terminating an employee would make their already-worked
     * shifts vanish from history the moment the status event lands.
     *
     * @return Collection<int, Employee>
     */
    private function roster(Store $store, array $scheduledEmployeeIds, array $filters): Collection
    {
        $query = Employee::query()
            ->with(['positions', 'contacts', 'availabilityDays.times'])
            ->assignedToStore((string) $store->store_number)
            ->where(function ($q) use ($scheduledEmployeeIds) {
                $q->where('active', true);

                if ($scheduledEmployeeIds !== []) {
                    $q->orWhereIn('id', $scheduledEmployeeIds);
                }
            });

        if (filled($filters['search'] ?? null)) {
            $search = trim((string) $filters['search']);

            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            });
        }

        $employees = $query->orderBy('first_name')->orderBy('last_name')->get();

        $department = $filters['department'] ?? null;

        if (filled($department) && $department !== 'All') {
            $departments = $this->employees->departmentsByPosition($store);

            $employees = $employees->filter(function (Employee $employee) use ($departments, $department) {
                foreach ($employee->positions as $position) {
                    if (($departments[$position->id] ?? null) === $department) {
                        return true;
                    }
                }

                return false;
            })->values();
        }

        return $employees;
    }

    private function actualShiftDtos(Store $store, string $from, string $to, CarbonImmutable $weekStart, array $employeeIds): array
    {
        return ActualShift::query()
            ->where('store_id', $store->id)
            ->whereDate('shift_date', '>=', $from)
            ->whereDate('shift_date', '<=', $to)
            ->get()
            ->filter(fn (ActualShift $actual) => in_array((string) $actual->employee_id, $employeeIds, true))
            ->map(fn (ActualShift $actual) => [
                'id' => (string) $actual->id,
                'employee_id' => (string) $actual->employee_id,
                'planned_shift_id' => $actual->shift_assignment_id === null
                    ? null
                    : (string) $actual->shift_assignment_id,
                'shift_date' => $actual->shift_date?->toDateString(),
                'day_index' => $this->weeks->dayIndexFor(CarbonImmutable::parse($actual->shift_date), $weekStart),
                'start_time' => substr((string) $actual->start_time, 0, 5),
                'end_time' => substr((string) $actual->end_time, 0, 5),
                'duration_minutes' => (int) $actual->duration_minutes,
                'label' => $actual->label,
                'type' => $actual->shift_type,
                'status' => $actual->status,
                'note' => $actual->note,
                'source' => $actual->source,
            ])
            ->values()
            ->all();
    }

    /**
     * Multi-day leave is expanded to one entry per day, because the grid is
     * per-day and would otherwise have to do calendar maths itself.
     */
    private function timeOffDtos(Store $store, string $from, string $to, CarbonImmutable $weekStart, array $employeeIds): array
    {
        $entries = TimeOff::query()
            ->where(function ($query) use ($store) {
                $query->where('store_id', $store->id)->orWhereNull('store_id');
            })
            ->where('status', 'approved')
            ->overlappingDates($from, $to)
            ->get();

        $dtos = [];

        foreach ($entries as $entry) {
            if (!in_array((string) $entry->employee_id, $employeeIds, true)) {
                continue;
            }

            $cursor = CarbonImmutable::parse(max($entry->start_date->toDateString(), $from));
            $last = CarbonImmutable::parse(min($entry->end_date->toDateString(), $to));

            while ($cursor <= $last) {
                $dayIndex = $this->weeks->dayIndexFor($cursor, $weekStart);

                if ($dayIndex !== null) {
                    $dtos[] = [
                        'id' => "{$entry->id}-{$dayIndex}",
                        'time_off_id' => (string) $entry->id,
                        'employee_id' => (string) $entry->employee_id,
                        'day_index' => $dayIndex,
                        'date' => $cursor->toDateString(),
                        'type' => $entry->type,
                        'label' => $entry->label ?? ucfirst((string) $entry->type),
                        'status' => $entry->status,
                    ];
                }

                $cursor = $cursor->addDay();
            }
        }

        return $dtos;
    }

    private function publishedDto(Store $store, string $weekStart): ?array
    {
        $published = PublishedSchedule::query()
            ->where('store_id', $store->id)
            ->whereDate('week_start_date', $weekStart)
            ->whereNull('unpublished_at')
            ->latest('published_at')
            ->first();

        return $published === null ? null : [
            'id' => (string) $published->id,
            'week_start_date' => $published->week_start_date?->toDateString(),
            'week_label' => $published->week_label,
            'published_at' => $published->published_at?->toIso8601String(),
            'screenshot_url' => $published->screenshotUrl(),
            'shift_count' => (int) $published->shift_count,
            'total_hours' => round((int) $published->total_minutes / 60, 2),
        ];
    }

    /** humanity_position_id → name, used as the department label on each shift. */
    private function departmentNamesByHumanityId(Store $store): array
    {
        return HumanityPosition::query()->pluck('name', 'humanity_position_id')->all();
    }
}
