<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Models\Shift;
use App\Models\Store;
use App\Models\TcpJobCode;
use App\Models\TcpWorkSegment;
use App\Services\Scheduling\Support\HourlyCoverage;
use App\Services\Scheduling\Support\OutlierDetector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * How many people were actually on the clock, hour by hour, per weekday.
 *
 * This is the staffing half of the scheduling screen's history. The sales half
 * lives in LC_PIZZA_DATA (hourly_store_summary); both use store-local hours and
 * put the small hours after midnight on the previous business date, so the two
 * line up hour for hour.
 *
 * Actual staffing comes from tcp_work_segments (what TCP recorded), never from
 * the plan. The plan is returned alongside as `scheduled_headcount` so a manager
 * can see "we usually schedule 5 at 6 pm, and 4.2 actually work".
 *
 * Rules:
 *  - a business date with no worked time at all is skipped and does not count
 *    towards that weekday's average;
 *  - on a date that counts, an hour nobody worked is a real 0 sample;
 *  - a segment is capped at tcp.rollup.max_shift_hours, so a forgotten clock-out
 *    cannot pad the hours after it;
 *  - odd weeks are flagged, not hidden (see OutlierDetector): every figure
 *    carries `avg` (all weeks) and `typical` (odd weeks left out).
 */
class StaffingHistoryService
{
    /** Past punches only change in the nightly reconcile or a manager edit. */
    private const CACHE_SECONDS = 600;

    private const FALLBACK_CUTOFF_HOUR = 5;

    private const WEEKDAY_NAMES = [
        0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
        4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday',
    ];

    public function __construct(
        private readonly StoreTimezoneResolver $timezones,
        private readonly LaborCostCalculator $labor,
        private readonly WeekResolver $weeks,
    ) {
    }

    public function build(Store $store, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $key = sprintf('staffing-history:%d:%s:%s', $store->id, $start->toDateString(), $end->toDateString());

        return Cache::remember(
            $key,
            self::CACHE_SECONDS,
            fn () => $this->compute($store, $start, $end)
        );
    }

    private function compute(Store $store, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $settings = $store->settings();
        $timezone = $this->timezones->for($store);
        $cutoff = $this->businessDayCutoff((string) $settings->open_time, (string) $settings->close_time);
        $maxSeconds = (int) config('tcp.rollup.max_shift_hours', 16) * 3600;

        // Every instant that can belong to a business date in the window.
        $windowStart = CarbonImmutable::parse($start->toDateString() . sprintf(' %02d:00:00', $cutoff), $timezone)->utc();
        $windowEnd = CarbonImmutable::parse($end->addDay()->toDateString() . sprintf(' %02d:00:00', $cutoff), $timezone)->utc();

        $jobLabels = TcpJobCode::query()->pluck('description', 'tcp_job_code_id')->all();

        $segmentRows = TcpWorkSegment::query()
            ->where('store_id', $store->id)
            ->whereNotNull('ends_at_utc')
            ->where('starts_at_utc', '<', $windowEnd)
            ->where('ends_at_utc', '>', $windowStart)
            ->get(['employee_id', 'tcp_job_code_id', 'starts_at_utc', 'ends_at_utc']);

        $this->labor->preload($segmentRows->pluck('employee_id')->unique()->all());

        $segments = [];
        foreach ($segmentRows as $row) {
            $startTs = $row->starts_at_utc->getTimestamp();
            $endTs = min($row->ends_at_utc->getTimestamp(), $startTs + $maxSeconds);

            $segments[] = [
                'start' => $startTs,
                'end' => $endTs,
                'employee_id' => (int) $row->employee_id,
                'job' => $this->jobLabel($jobLabels[$row->tcp_job_code_id] ?? null),
                'rate' => $this->labor->rateFor((int) $row->employee_id, $row->starts_at_utc->toDateString(), $settings),
            ];
        }

        $planned = [];
        $shifts = Shift::query()
            ->forStore((int) $store->id)
            ->where('starts_at_utc', '<', $windowEnd)
            ->where('ends_at_utc', '>', $windowStart)
            ->with(['assignments' => fn ($q) => $q->where('status', 'assigned')])
            ->get(['id', 'starts_at_utc', 'ends_at_utc']);

        foreach ($shifts as $shift) {
            // One assignment is one person.
            for ($i = 0, $n = $shift->assignments->count(); $i < $n; $i++) {
                $planned[] = ['start' => $shift->starts_at_utc->getTimestamp(), 'end' => $shift->ends_at_utc->getTimestamp()];
            }
        }

        return $this->assemble(
            [
                'store_number' => (string) $store->store_number,
                'timezone' => $timezone,
                'open_time' => substr((string) $settings->open_time, 0, 5),
                'close_time' => substr((string) $settings->close_time, 0, 5),
                'week_start_dow' => $this->weeks->weekStartDow($settings),
                'cutoff' => $cutoff,
            ],
            $start,
            $end,
            $segments,
            $planned
        );
    }

    /**
     * The first clock hour of a business day, from the store's own hours: the
     * middle of the time it is closed, so the small hours after closing still
     * belong to the day that just ended. A store that is never closed (or has no
     * hours yet) falls back to 05:00.
     */
    public function businessDayCutoff(string $openTime, string $closeTime): int
    {
        $toMinutes = static function (string $time): ?int {
            if (!preg_match('/^(\d{1,2}):(\d{2})/', $time, $m)) {
                return null;
            }

            return ((int) $m[1]) * 60 + (int) $m[2];
        };

        $open = $toMinutes($openTime);
        $close = $toMinutes($closeTime);

        if ($open === null || $close === null) {
            return self::FALLBACK_CUTOFF_HOUR;
        }

        $closedFor = ($open - $close + 1440) % 1440;
        if ($closedFor === 0) {
            return self::FALLBACK_CUTOFF_HOUR;
        }

        return ((int) round(($close + $closedFor / 2) / 60)) % 24;
    }

    /**
     * Pure: no database, no framework state.
     *
     * @param array{store_number: string, timezone: string, open_time: string, close_time: string, week_start_dow: int, cutoff: int} $store
     * @param list<array{start: int, end: int, employee_id: int, job: string, rate: float}> $segments UTC timestamps
     * @param list<array{start: int, end: int}> $planned UTC timestamps, one per person
     */
    public function assemble(
        array $store,
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $segments,
        array $planned
    ): array {
        $tz = $store['timezone'];
        $cutoff = $store['cutoff'];
        $startDate = $start->toDateString();
        $endDate = $end->toDateString();

        // date => hour => person-minutes
        $actual = [];
        $byJob = [];          // date => hour => label => person-minutes
        $employees = [];      // date => employeeId => true
        $cost = [];           // date => dollars

        foreach ($segments as $segment) {
            $one = [];
            HourlyCoverage::addInterval($one, $segment['start'], $segment['end'], $tz, $cutoff);

            foreach ($one as $date => $hours) {
                if ($date < $startDate || $date > $endDate) {
                    continue;
                }
                foreach ($hours as $hour => $minutes) {
                    $actual[$date][$hour] = ($actual[$date][$hour] ?? 0.0) + $minutes;
                    $byJob[$date][$hour][$segment['job']] = ($byJob[$date][$hour][$segment['job']] ?? 0.0) + $minutes;
                    $cost[$date] = ($cost[$date] ?? 0.0) + ($minutes / 60) * $segment['rate'];
                }
                $employees[$date][$segment['employee_id']] = true;
            }
        }

        $scheduled = [];
        foreach ($planned as $shift) {
            HourlyCoverage::addInterval($scheduled, $shift['start'], $shift['end'], $tz, $cutoff);
        }

        // Which dates count, per weekday.
        $counted = [];
        $skipped = [];
        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            $date = $d->toDateString();
            if (array_sum($actual[$date] ?? []) > 0) {
                $counted[$d->dayOfWeek][] = $date;
            } else {
                $skipped[$d->dayOfWeek][] = $date;
            }
        }

        $order = [];
        for ($i = 0; $i < 7; $i++) {
            $order[] = ($store['week_start_dow'] + $i) % 7;
        }

        $weekdays = [];
        $anomalies = [];

        foreach ($order as $weekday) {
            $dates = $counted[$weekday] ?? [];

            // Daily block.
            $laborHours = [];
            $people = [];
            foreach ($dates as $date) {
                $laborHours[$date] = array_sum($actual[$date]) / 60;
                $people[$date] = (float) count($employees[$date] ?? []);
            }
            $laborStats = OutlierDetector::analyze($laborHours);
            $flaggedDays = [];
            foreach ($laborStats['anomalies'] as $a) {
                $flaggedDays[$a['date']] = $a;
                $anomalies[] = $a + ['weekday' => $weekday, 'hour' => null, 'metric' => 'labor_hours'];
            }
            $excludedDays = array_keys($flaggedDays);

            $costByDate = [];
            foreach ($dates as $date) {
                $costByDate[$date] = $cost[$date] ?? 0.0;
            }

            // Hours block.
            $hourSet = [];
            foreach ($dates as $date) {
                foreach (array_keys($actual[$date] ?? []) as $h) {
                    $hourSet[$h] = true;
                }
                foreach (array_keys($scheduled[$date] ?? []) as $h) {
                    $hourSet[$h] = true;
                }
            }
            $hourList = array_keys($hourSet);
            sort($hourList);

            // How big "a lot of people" is at this store on this day: its usual
            // busiest hour. Judges whether a difference is worth flagging.
            $hourScale = 0.0;
            foreach ($hourList as $hour) {
                $perDate = [];
                foreach ($dates as $date) {
                    $perDate[] = ($actual[$date][$hour] ?? 0.0) / 60;
                }
                $hourScale = max($hourScale, OutlierDetector::median($perDate));
            }

            $hours = [];
            foreach ($hourList as $hour) {
                $series = [];
                $plan = [];
                $jobSeries = [];
                foreach ($dates as $date) {
                    $series[$date] = ($actual[$date][$hour] ?? 0.0) / 60;
                    $plan[$date] = ($scheduled[$date][$hour] ?? 0.0) / 60;
                    foreach ($byJob[$date][$hour] ?? [] as $label => $minutes) {
                        $jobSeries[$label][$date] = $minutes / 60;
                    }
                }

                $headcount = OutlierDetector::analyze($series, $hourScale);

                $jobs = [];
                foreach ($jobSeries as $label => $perDate) {
                    $jobs[$label] = round(array_sum($perDate) / count($dates), 2);
                }
                arsort($jobs);

                foreach ($headcount['anomalies'] as $a) {
                    $hourAnomaly = $a + ['weekday' => $weekday, 'hour' => $hour, 'metric' => 'headcount'];
                    if (!$this->isExplainedByDay($hourAnomaly, $flaggedDays)) {
                        $anomalies[] = $hourAnomaly;
                    }
                }

                $hours[] = [
                    'hour' => $hour,
                    'headcount' => $headcount,
                    'scheduled_headcount' => $this->plain($plan, []),
                    'by_job' => $jobs,
                ];
            }

            $weekdays[] = [
                'weekday' => $weekday,
                'name' => self::WEEKDAY_NAMES[$weekday],
                'days_sampled' => count($dates),
                'dates' => $dates,
                'skipped_dates' => $skipped[$weekday] ?? [],
                'daily' => [
                    'labor_hours' => $laborStats,
                    'labor_cost' => $this->plain($costByDate, $excludedDays, 2),
                    'employees_worked' => $this->plain($people, $excludedDays, 1),
                ],
                'hours' => $hours,
            ];
        }

        usort($anomalies, static fn ($a, $b) => [$b['date'], $a['hour'] ?? -1] <=> [$a['date'], $b['hour'] ?? -1]);

        return [
            'filtering' => [
                'store' => $store['store_number'],
                'start_date' => $startDate,
                'end_date' => $endDate,
                'week_start_dow' => $store['week_start_dow'],
            ],
            'store' => [
                'timezone' => $tz,
                'open_time' => $store['open_time'],
                'close_time' => $store['close_time'],
                'business_day_cutoff' => $cutoff,
            ],
            'weekdays' => $weekdays,
            'anomalies' => $anomalies,
        ];
    }

    /** "Crew Member - 3795-01" -> "Crew Member". */
    private function jobLabel(?string $description): string
    {
        if ($description === null || trim($description) === '') {
            return 'Unassigned';
        }

        return trim(preg_replace('/\s+-\s+\d+-\d+$/', '', $description));
    }

    /**
     * A flagged hour on a date whose whole day is flagged the same way is the
     * day showing through, and is reported under the day.
     */
    private function isExplainedByDay(array $hourAnomaly, array $dayFlags): bool
    {
        $day = $dayFlags[$hourAnomaly['date']] ?? null;
        if ($day === null || $day['kind'] !== $hourAnomaly['kind']) {
            return false;
        }

        if ($day['ratio'] <= 0 || $hourAnomaly['ratio'] <= 0) {
            return false;
        }

        return abs(log($hourAnomaly['ratio'] / $day['ratio'])) <= log(1.5);
    }

    /**
     * @param array<string, float> $samples
     * @param list<string> $excludeDates
     * @return array{avg: float, typical: float}
     */
    private function plain(array $samples, array $excludeDates, int $precision = 2): array
    {
        if ($samples === []) {
            return ['avg' => 0.0, 'typical' => 0.0];
        }

        $avg = array_sum($samples) / count($samples);
        $kept = array_diff_key($samples, array_flip($excludeDates));
        $typical = $kept === [] ? $avg : array_sum($kept) / count($kept);

        return ['avg' => round($avg, $precision), 'typical' => round($typical, $precision)];
    }
}
