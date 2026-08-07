<?php

namespace App\Services\Scheduling;

use App\Models\Employee;
use App\Models\ScheduleAvailabilityOverride;
use App\Models\Store;
use App\Models\StoreScheduleSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The scheduling UI wants BLOCKED windows ("not available Saturdays"), but
 * hiring stores the opposite: the windows an employee IS available. So this
 * inverts the replicated availability against store hours, then layers
 * manager-entered overrides on top.
 *
 * An employee with no availability rows at all is treated as fully available —
 * hiring simply may not have collected it, and blocking every such employee
 * would make the roster unusable.
 */
class AvailabilityProjector
{
    private const DAY_NAMES = [
        'sunday' => 0,
        'monday' => 1,
        'tuesday' => 2,
        'wednesday' => 3,
        'thursday' => 4,
        'friday' => 5,
        'saturday' => 6,
    ];

    public function __construct(private readonly WeekResolver $weeks)
    {
    }

    /**
     * Blocked windows for a week, in the frontend's AvailabilityRule shape.
     *
     * @param  Collection<int, Employee>  $employees  with availabilityDays.times loaded
     * @return array<int, array<string, mixed>>
     */
    public function forWeek(
        Store $store,
        Collection $employees,
        CarbonImmutable $weekStart,
        ?StoreScheduleSetting $settings = null,
    ): array {
        $settings ??= $store->settings();
        $open = $this->hhmm($settings->open_time ?? '09:00');
        $close = $this->hhmm($settings->close_time ?? '00:00');

        $dates = $this->weeks->datesForWeek($weekStart);
        $overrides = $this->overridesFor($store, $employees->pluck('id')->all(), $weekStart);

        $rules = [];

        foreach ($employees as $employee) {
            $days = $employee->relationLoaded('availabilityDays')
                ? $employee->availabilityDays
                : $employee->availabilityDays()->with('times')->get();

            // Nothing on file → assume available, don't block the whole roster.
            $hasProfile = $days->isNotEmpty();

            foreach ($dates as $dayIndex => $date) {
                $dow = (int) $date->dayOfWeek;

                if ($hasProfile) {
                    $windows = $this->availableWindowsFor($days, $dow);

                    foreach ($this->invert($windows, $open, $close) as $blocked) {
                        $rules[] = [
                            'id' => "profile-{$employee->id}-{$dayIndex}-{$blocked['from']}",
                            'employee_id' => (string) $employee->id,
                            'day_index' => $dayIndex,
                            'date' => $date->toDateString(),
                            'all_day' => $blocked['all_day'],
                            'start_time' => $blocked['all_day'] ? null : $blocked['from'],
                            'end_time' => $blocked['all_day'] ? null : $blocked['to'],
                            'reason' => $blocked['all_day']
                                ? 'Not available on ' . $date->format('l') . 's'
                                : 'Outside stated availability',
                            'source' => 'employee_profile',
                        ];
                    }
                }

                foreach ($overrides as $override) {
                    if ((int) $override->employee_id !== (int) $employee->id) {
                        continue;
                    }

                    if (!$this->overrideAppliesOn($override, $date, $dow)) {
                        continue;
                    }

                    $rules[] = [
                        'id' => "override-{$override->id}-{$dayIndex}",
                        'employee_id' => (string) $employee->id,
                        'day_index' => $dayIndex,
                        'date' => $date->toDateString(),
                        'all_day' => (bool) $override->all_day,
                        'start_time' => $override->all_day ? null : $this->hhmm($override->start_time),
                        'end_time' => $override->all_day ? null : $this->hhmm($override->end_time),
                        'reason' => $override->reason ?? 'Unavailable',
                        'source' => 'override',
                    ];
                }
            }
        }

        return $rules;
    }

    /**
     * Does a proposed shift collide with a blocked window?
     * Works on the projected rules so the API and the guard can never disagree.
     */
    public function blocking(array $rules, int $employeeId, string $date, string $startTime, string $endTime): array
    {
        $start = $this->minutes($startTime);
        $end = $this->minutes($endTime);

        if ($end <= $start) {
            $end += 1440;
        }

        return array_values(array_filter($rules, function (array $rule) use ($employeeId, $date, $start, $end) {
            if ((string) $rule['employee_id'] !== (string) $employeeId || $rule['date'] !== $date) {
                return false;
            }

            if ($rule['all_day']) {
                return true;
            }

            $ruleStart = $this->minutes((string) $rule['start_time']);
            $ruleEnd = $this->minutes((string) $rule['end_time']);

            if ($ruleEnd <= $ruleStart) {
                $ruleEnd += 1440;
            }

            return $start < $ruleEnd && $end > $ruleStart;
        }));
    }

    /** @return array<int, array{from:string,to:string}> */
    private function availableWindowsFor(Collection $days, int $dow): array
    {
        $name = array_search($dow, self::DAY_NAMES, true);
        $windows = [];

        foreach ($days as $day) {
            if (strtolower((string) $day->day_of_week) !== $name) {
                continue;
            }

            $times = $day->relationLoaded('times') ? $day->times : $day->times()->get();

            foreach ($times as $time) {
                $windows[] = [
                    'from' => $this->hhmm($time->available_from),
                    'to' => $this->hhmm($time->available_to),
                ];
            }

            // A day row with no times means available all day for that shift
            // type — hiring records the day even when the hours are open.
            if ($times->isEmpty()) {
                $windows[] = ['from' => '00:00', 'to' => '00:00'];
            }
        }

        return $windows;
    }

    /**
     * Store hours minus the available windows = the blocked windows.
     *
     * @param  array<int, array{from:string,to:string}>  $windows
     * @return array<int, array{from:string,to:string,all_day:bool}>
     */
    private function invert(array $windows, string $open, string $close): array
    {
        if ($windows === []) {
            return [['from' => $open, 'to' => $close, 'all_day' => true]];
        }

        $openMin = $this->minutes($open);
        $closeMin = $this->minutes($close);

        if ($closeMin <= $openMin) {
            $closeMin += 1440; // store closes after midnight
        }

        $covered = [];

        foreach ($windows as $window) {
            $from = $this->minutes($window['from']);
            $to = $this->minutes($window['to']);

            if ($to <= $from) {
                $to += 1440;
            }

            $covered[] = [max($from, $openMin), min($to, $closeMin)];
        }

        usort($covered, fn ($a, $b) => $a[0] <=> $b[0]);

        $blocked = [];
        $cursor = $openMin;

        foreach ($covered as [$from, $to]) {
            if ($from > $cursor) {
                $blocked[] = ['from' => $this->fromMinutes($cursor), 'to' => $this->fromMinutes($from), 'all_day' => false];
            }

            $cursor = max($cursor, $to);
        }

        if ($cursor < $closeMin) {
            $blocked[] = ['from' => $this->fromMinutes($cursor), 'to' => $this->fromMinutes($closeMin), 'all_day' => false];
        }

        // Fully blocked → collapse into a single all-day rule so the UI shows
        // one badge instead of a fragmented bar.
        if (count($blocked) === 1 && $blocked[0]['from'] === $open && $this->minutes($blocked[0]['to']) >= $closeMin) {
            return [['from' => $open, 'to' => $close, 'all_day' => true]];
        }

        return $blocked;
    }

    /** @return Collection<int, ScheduleAvailabilityOverride> */
    private function overridesFor(Store $store, array $employeeIds, CarbonImmutable $weekStart): Collection
    {
        $weekEnd = $weekStart->addDays(6);

        return ScheduleAvailabilityOverride::query()
            ->where('store_id', $store->id)
            ->whereIn('employee_id', $employeeIds)
            ->where(function ($query) use ($weekStart, $weekEnd) {
                $query->whereNull('effective_from')->orWhere('effective_from', '<=', $weekEnd->toDateString());
            })
            ->where(function ($query) use ($weekStart) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $weekStart->toDateString());
            })
            ->get();
    }

    private function overrideAppliesOn(ScheduleAvailabilityOverride $override, CarbonImmutable $date, int $dow): bool
    {
        if ($override->scope === 'date') {
            return $override->specific_date?->toDateString() === $date->toDateString();
        }

        return (int) $override->day_of_week === $dow;
    }

    private function hhmm(mixed $time): string
    {
        $time = (string) $time;

        return $time === '' ? '00:00' : substr($time, 0, 5);
    }

    private function minutes(string $time): int
    {
        [$h, $m] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

        return $h * 60 + $m;
    }

    private function fromMinutes(int $minutes): string
    {
        $minutes %= 1440;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
