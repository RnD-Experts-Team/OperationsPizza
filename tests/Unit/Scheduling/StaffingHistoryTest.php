<?php

namespace Tests\Unit\Scheduling;

use App\Services\Scheduling\LaborCostCalculator;
use App\Services\Scheduling\StaffingHistoryService;
use App\Services\Scheduling\StoreTimezoneResolver;
use App\Services\Scheduling\Support\HourlyCoverage;
use App\Services\Scheduling\Support\OutlierDetector;
use App\Services\Scheduling\WeekResolver;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests: no database, no booted app.
 */
class StaffingHistoryTest extends TestCase
{
    private const TZ = 'America/Chicago';

    private static function ts(string $localDateTime, string $tz = self::TZ): int
    {
        return CarbonImmutable::parse($localDateTime, $tz)->getTimestamp();
    }

    private function service(): StaffingHistoryService
    {
        return new StaffingHistoryService(new StoreTimezoneResolver(), new LaborCostCalculator(), new WeekResolver());
    }

    private function storeInfo(): array
    {
        return [
            'store_number' => '03795-00001', 'timezone' => self::TZ,
            'open_time' => '10:00', 'close_time' => '00:00', 'week_start_dow' => 2, 'cutoff' => 5,
        ];
    }

    private function segment(string $in, string $out, int $employee = 1, string $job = 'Crew Member', float $rate = 10.0): array
    {
        return [
            'start' => self::ts($in), 'end' => self::ts($out),
            'employee_id' => $employee, 'job' => $job, 'rate' => $rate,
        ];
    }

    // ── HourlyCoverage ────────────────────────────────────────────────────

    public function test_a_late_segment_splits_across_hours_and_the_after_midnight_part_keeps_the_previous_date(): void
    {
        $minutes = [];
        HourlyCoverage::addInterval($minutes, self::ts('2026-09-01 22:30'), self::ts('2026-09-02 01:15'), self::TZ, 5);

        $this->assertSame([22 => 30.0, 23 => 60.0, 0 => 60.0, 1 => 15.0], $minutes['2026-09-01']);
        $this->assertArrayNotHasKey('2026-09-02', $minutes);
    }

    public function test_the_morning_after_the_cutoff_starts_a_new_business_date(): void
    {
        $minutes = [];
        HourlyCoverage::addInterval($minutes, self::ts('2026-09-02 04:30'), self::ts('2026-09-02 06:00'), self::TZ, 5);

        $this->assertSame([4 => 30.0], $minutes['2026-09-01']);
        $this->assertSame([5 => 60.0], $minutes['2026-09-02']);
    }

    public function test_an_empty_or_backwards_interval_adds_nothing(): void
    {
        $minutes = [];
        HourlyCoverage::addInterval($minutes, self::ts('2026-09-01 10:00'), self::ts('2026-09-01 10:00'), self::TZ, 5);
        HourlyCoverage::addInterval($minutes, self::ts('2026-09-01 11:00'), self::ts('2026-09-01 10:00'), self::TZ, 5);

        $this->assertSame([], $minutes);
    }

    public function test_the_spring_forward_day_buckets_on_local_hours(): void
    {
        // 2026-03-08: 02:00 CST jumps to 03:00 CDT. 01:00 to 04:00 local is 2 real hours.
        $minutes = [];
        HourlyCoverage::addInterval($minutes, self::ts('2026-03-08 01:00'), self::ts('2026-03-08 04:00'), self::TZ, 5);

        $this->assertSame([1 => 60.0, 3 => 60.0], $minutes['2026-03-07']);
    }

    // ── OutlierDetector: nothing configured, it reads the data ────────────

    /** Second value is the scale: how big the thing measured is (0 = its own median). */
    public static function outlierCases(): array
    {
        return [
            'spike' => [[400, 410, 395, 820], 0.0, ['spike'], false],
            'dip' => [[2400, 2350, 1200, 2450], 0.0, ['dip'], false],
            // A $12 hour among $25 hours, in a store whose busy hours do $400.
            'tiny next to a big scale' => [[12, 25, 20, 18], 400.0, [], false],
            'weeks that just differ' => [[100, 300, 100, 300], 0.0, [], true],
            'too few samples' => [[400, 900], 0.0, [], false],
            // People in an hour, in a store whose busy hour has 4.
            'headcount spike' => [[4, 4.2, 3.8, 7], 4.0, ['spike'], false],
            'small headcount wobble' => [[1, 1.2, 0.8, 1.6], 4.0, [], false],
            // A steady hour reacts to a smaller change than a jumpy one.
            'steady hour flags a modest jump' => [[100, 101, 99, 150], 0.0, ['spike'], false],
            'jumpy hour does not' => [[100, 160, 120, 150], 0.0, [], false],
        ];
    }

    #[DataProvider('outlierCases')]
    public function test_outlier_detector(array $values, float $scale, array $kinds, bool $volatile): void
    {
        $samples = [];
        foreach ($values as $i => $v) {
            $samples['2026-09-' . str_pad((string) ($i * 7 + 1), 2, '0', STR_PAD_LEFT)] = $v;
        }

        $r = OutlierDetector::analyze($samples, $scale);

        $this->assertSame($kinds, array_column($r['anomalies'], 'kind'));
        $this->assertSame($volatile, $r['volatile']);
    }

    public function test_the_business_day_starts_in_the_middle_of_the_closed_hours(): void
    {
        $service = $this->service();

        $this->assertSame(5, $service->businessDayCutoff('09:00', '00:00')); // closed 00:00-09:00
        $this->assertSame(4, $service->businessDayCutoff('06:00', '02:00')); // closed 02:00-06:00 -> 04:00
        $this->assertSame(5, $service->businessDayCutoff('00:00', '00:00')); // never closed
        $this->assertSame(5, $service->businessDayCutoff('', ''));           // no hours yet
    }

    public function test_typical_leaves_the_odd_week_out_and_avg_keeps_it(): void
    {
        $r = OutlierDetector::analyze(
            ['2026-09-01' => 400, '2026-09-08' => 410, '2026-09-15' => 395, '2026-09-22' => 820]
        );

        $this->assertEqualsWithDelta(506.25, $r['avg'], 0.01);
        $this->assertEqualsWithDelta(401.67, $r['typical'], 0.01);
    }

    // ── Assembly ──────────────────────────────────────────────────────────

    /** Tuesdays in Sep 2026: 1, 8, 15, 22. */
    private function tuesday(array $result): array
    {
        foreach ($result['weekdays'] as $w) {
            if ($w['weekday'] === 2) {
                return $w;
            }
        }
        $this->fail('no Tuesday block');
    }

    private function hour(array $weekday, int $hour): array
    {
        foreach ($weekday['hours'] as $h) {
            if ($h['hour'] === $hour) {
                return $h;
            }
        }
        $this->fail("no hour {$hour}");
    }

    private function history(array $segments, array $planned = []): array
    {
        return $this->service()->assemble(
            $this->storeInfo(),
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-09-28'),
            $segments,
            $planned
        );
    }

    public function test_weekdays_start_on_the_stores_week_start(): void
    {
        $r = $this->history([]);

        $this->assertSame([2, 3, 4, 5, 6, 0, 1], array_column($r['weekdays'], 'weekday'));
    }

    public function test_a_day_nobody_worked_is_left_out_of_the_denominator(): void
    {
        // Two people 17:00-19:00 on three Tuesdays; nobody on Sep 8.
        $segments = [];
        foreach (['2026-09-01', '2026-09-15', '2026-09-22'] as $date) {
            $segments[] = $this->segment("{$date} 17:00", "{$date} 19:00", 1);
            $segments[] = $this->segment("{$date} 17:00", "{$date} 19:00", 2);
        }

        $tue = $this->tuesday($this->history($segments));

        $this->assertSame(3, $tue['days_sampled']);
        $this->assertSame(['2026-09-08'], $tue['skipped_dates']);
        $this->assertSame(2.0, $this->hour($tue, 17)['headcount']['avg']);
        $this->assertSame(4.0, $tue['daily']['labor_hours']['avg']);
        $this->assertSame(2.0, $tue['daily']['employees_worked']['avg']);
        $this->assertSame(40.0, $tue['daily']['labor_cost']['avg']);
    }

    public function test_an_hour_nobody_worked_on_a_counted_day_is_zero(): void
    {
        // Two counted Tuesdays; 19:00 is only staffed on one of them.
        $segments = [
            $this->segment('2026-09-01 17:00', '2026-09-01 19:00'),
            $this->segment('2026-09-15 17:00', '2026-09-15 20:00'),
        ];

        $tue = $this->tuesday($this->history($segments));

        $this->assertSame(2, $tue['days_sampled']);
        $this->assertSame(0.5, $this->hour($tue, 19)['headcount']['avg']);
        $this->assertSame(0.0, $this->hour($tue, 19)['headcount']['low']);
    }

    public function test_an_overnight_close_lands_on_the_previous_business_date_and_weekday(): void
    {
        // Tuesday 22:00 to Wednesday 01:00 is a Tuesday shift.
        $tue = $this->tuesday($this->history([$this->segment('2026-09-01 22:00', '2026-09-02 01:00')]));

        $this->assertSame(['2026-09-01'], $tue['dates']);
        $this->assertSame(1.0, $this->hour($tue, 0)['headcount']['avg']);
        $this->assertSame(3.0, $tue['daily']['labor_hours']['avg']);
    }

    public function test_the_job_split_and_planned_headcount_come_through(): void
    {
        $segments = [
            $this->segment('2026-09-01 17:00', '2026-09-01 18:00', 1, 'Manager'),
            $this->segment('2026-09-01 17:00', '2026-09-01 18:00', 2, 'Crew Member'),
        ];
        $planned = [
            ['start' => self::ts('2026-09-01 17:00'), 'end' => self::ts('2026-09-01 18:00')],
            ['start' => self::ts('2026-09-01 17:00'), 'end' => self::ts('2026-09-01 18:00')],
            ['start' => self::ts('2026-09-01 17:00'), 'end' => self::ts('2026-09-01 18:00')],
        ];

        $h = $this->hour($this->tuesday($this->history($segments, $planned)), 17);

        $this->assertSame(['Manager' => 1.0, 'Crew Member' => 1.0], $h['by_job']);
        $this->assertSame(3.0, $h['scheduled_headcount']['avg']);
        $this->assertSame(2.0, $h['headcount']['avg']);
    }

    public function test_an_odd_day_is_flagged_and_typical_leaves_it_out(): void
    {
        // Four Tuesdays: 4, 4, 4 and then 12 people all evening on Sep 22.
        $segments = [];
        foreach (['2026-09-01' => 4, '2026-09-08' => 4, '2026-09-15' => 4, '2026-09-22' => 12] as $date => $people) {
            for ($p = 1; $p <= $people; $p++) {
                $segments[] = $this->segment("{$date} 17:00", "{$date} 21:00", $p);
            }
        }

        $r = $this->history($segments);
        $tue = $this->tuesday($r);
        $labor = $tue['daily']['labor_hours'];

        $this->assertSame(1, count($labor['anomalies']));
        $this->assertSame('spike', $labor['anomalies'][0]['kind']);
        $this->assertSame('2026-09-22', $labor['anomalies'][0]['date']);
        $this->assertSame(24.0, $labor['avg']);
        $this->assertSame(16.0, $labor['typical']);

        // The same spike shows through in each hour; it is reported once, under the day.
        $hourly = array_filter($r['anomalies'], fn ($a) => $a['metric'] === 'headcount');
        $this->assertCount(0, $hourly);
        $this->assertSame(4.0, $this->hour($tue, 17)['headcount']['typical']);
        $this->assertSame(6.0, $this->hour($tue, 17)['headcount']['avg']);
    }

    public function test_an_odd_hour_inside_a_normal_day_is_reported_on_its_own(): void
    {
        // Same day otherwise, but on Sep 22 six extra people work one hour.
        $segments = [];
        foreach (['2026-09-01', '2026-09-08', '2026-09-15', '2026-09-22'] as $date) {
            for ($p = 1; $p <= 4; $p++) {
                $segments[] = $this->segment("{$date} 17:00", "{$date} 21:00", $p);
            }
        }
        for ($p = 10; $p < 16; $p++) {
            $segments[] = $this->segment('2026-09-22 18:00', '2026-09-22 19:00', $p);
        }

        $r = $this->history($segments);
        $hourly = array_values(array_filter($r['anomalies'], fn ($a) => $a['metric'] === 'headcount'));

        $this->assertCount(1, $hourly);
        $this->assertSame(18, $hourly[0]['hour']);
        $this->assertSame('2026-09-22', $hourly[0]['date']);
        $this->assertSame('spike', $hourly[0]['kind']);
    }
}
