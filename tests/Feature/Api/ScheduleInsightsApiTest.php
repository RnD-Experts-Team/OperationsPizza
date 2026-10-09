<?php

namespace Tests\Feature\Api;

use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Store;
use App\Models\TcpJobCode;
use App\Models\TcpWorkSegment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GET /v1/stores/{storeId}/schedule/insights against the real tables: the
 * segment query, the planned-shift query, the job-code labels and the wage
 * lookup. The bucketing and outlier maths have their own pure unit tests.
 */
class ScheduleInsightsApiTest extends TestCase
{
    use RefreshDatabase;

    private const STORE = '03759-00001';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.auth_server.base_url' => 'http://auth.test',
            'services.auth_server.verify_path' => '/api/v1/auth/token/verify',
            'services.auth_server.service_name' => 'operations-system',
            'services.auth_server.call_token' => 'service-token',
            'cache.default' => 'array',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'auth.test/*' => Http::response([
                'active' => true,
                'subject_type' => 'user',
                'user' => ['id' => 9, 'name' => 'Dana', 'email' => 'dana@example.com'],
                'roles' => ['Store Manager'],
                'permissions' => [],
                'ext' => ['authorized' => true],
            ]),
        ]);

        User::query()->create(['id' => 9, 'name' => 'Dana', 'email' => 'dana@example.com']);

        $store = Store::query()->create(['id' => 1, 'store_number' => self::STORE, 'name' => 'Downtown', 'timezone' => 'America/Chicago']);
        $store->settings();

        foreach ([501 => 'Marco', 502 => 'Lena', 503 => 'Theo'] as $id => $name) {
            Employee::query()->create([
                'id' => $id, 'first_name' => $name, 'last_name' => 'Test',
                'active' => true, 'current_status' => 'hired', 'hourly_rate' => 12,
            ]);
        }

        TcpJobCode::query()->create([
            'tcp_job_code_id' => '37950101', 'description' => 'Crew Member - 3795-01',
            'store_number' => self::STORE, 'clockable' => true, 'is_active' => true,
        ]);
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer 1|test-token', 'Accept' => 'application/json'];
    }

    /** Local Chicago wall clock to a UTC Carbon. */
    private function utc(string $local): CarbonImmutable
    {
        return CarbonImmutable::parse($local, 'America/Chicago')->utc();
    }

    private function segment(string $id, int $employee, string $in, string $out): void
    {
        $start = $this->utc($in);
        $end = $this->utc($out);

        TcpWorkSegment::query()->create([
            'tcp_work_segment_id' => $id, 'employee_id' => $employee, 'store_id' => 1,
            'tcp_employee_id' => (string) $employee, 'tcp_job_code_id' => '37950101',
            'origin' => 'discovered',
            'time_in' => $in, 'time_out' => $out,
            'starts_at_utc' => $start, 'ends_at_utc' => $end,
            'duration_minutes' => (int) $start->diffInMinutes($end),
        ]);
    }

    public function test_it_averages_actual_staffing_per_weekday_and_hour(): void
    {
        // Tuesdays Sep 1 and Sep 15: Marco 17:00-19:00; Lena only on the 15th.
        $this->segment('a', 501, '2026-09-01 17:00:00', '2026-09-01 19:00:00');
        $this->segment('b', 501, '2026-09-15 17:00:00', '2026-09-15 19:00:00');
        $this->segment('c', 502, '2026-09-15 17:00:00', '2026-09-15 19:00:00');

        $response = $this->getJson(
            '/api/v1/stores/' . self::STORE . '/schedule/insights?start_date=2026-09-01&end_date=2026-09-28',
            $this->headers()
        );

        $response->assertOk();

        $data = $response->json('data');
        $this->assertSame([2, 3, 4, 5, 6, 0, 1], array_column($data['weekdays'], 'weekday'));
        $this->assertSame('America/Chicago', $data['store']['timezone']);

        $tuesday = $data['weekdays'][0];
        $this->assertSame(2, $tuesday['days_sampled']);
        $this->assertSame(['2026-09-08', '2026-09-22'], $tuesday['skipped_dates']);

        $hour17 = collect($tuesday['hours'])->firstWhere('hour', 17);
        $this->assertEquals(1.5, $hour17['headcount']['avg']);
        $this->assertEquals(['Crew Member' => 1.5], $hour17['by_job']);

        // 4 worked hours on the 15th, 2 on the 1st: average 3, at $12.
        $this->assertEquals(3.0, $tuesday['daily']['labor_hours']['avg']);
        $this->assertEquals(36.0, $tuesday['daily']['labor_cost']['avg']);
    }

    public function test_planned_people_are_returned_beside_the_actual_ones(): void
    {
        $this->segment('a', 501, '2026-09-01 17:00:00', '2026-09-01 19:00:00');

        $shift = Shift::query()->create([
            'store_id' => 1, 'shift_date' => '2026-09-01',
            'start_time' => '17:00:00', 'end_time' => '19:00:00',
            'starts_at_utc' => $this->utc('2026-09-01 17:00:00'),
            'ends_at_utc' => $this->utc('2026-09-01 19:00:00'),
            'duration_minutes' => 120, 'crosses_midnight' => false,
            'label' => 'Evening', 'shift_type' => 'evening', 'origin' => Shift::ORIGIN_OPERATIONS,
        ]);
        foreach ([501, 502] as $employee) {
            ShiftAssignment::query()->create(['shift_id' => $shift->id, 'employee_id' => $employee, 'status' => 'assigned']);
        }
        // A released slot is nobody.
        ShiftAssignment::query()->create(['shift_id' => $shift->id, 'employee_id' => 503, 'status' => 'released']);

        $response = $this->getJson(
            '/api/v1/stores/' . self::STORE . '/schedule/insights?start_date=2026-09-01&end_date=2026-09-28',
            $this->headers()
        );

        $response->assertOk();
        $hour17 = collect($response->json('data.weekdays.0.hours'))->firstWhere('hour', 17);

        $this->assertEquals(1.0, $hour17['headcount']['avg']);
        $this->assertEquals(2.0, $hour17['scheduled_headcount']['avg']);
    }

    public function test_a_clocked_in_segment_is_not_counted_yet(): void
    {
        $start = $this->utc('2026-09-01 17:00:00');

        TcpWorkSegment::query()->create([
            'tcp_work_segment_id' => 'open', 'employee_id' => 501, 'store_id' => 1,
            'tcp_employee_id' => '501', 'origin' => 'discovered',
            'time_in' => '2026-09-01 17:00:00', 'time_out' => null,
            'starts_at_utc' => $start, 'ends_at_utc' => null,
        ]);

        $response = $this->getJson(
            '/api/v1/stores/' . self::STORE . '/schedule/insights?start_date=2026-09-01&end_date=2026-09-28',
            $this->headers()
        );

        $response->assertOk();
        $this->assertSame(0, $response->json('data.weekdays.0.days_sampled'));
    }

    public function test_the_default_window_and_validation(): void
    {
        $this->getJson('/api/v1/stores/' . self::STORE . '/schedule/insights', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.filtering.week_start_dow', 2);

        $this->getJson('/api/v1/stores/' . self::STORE . '/schedule/insights?start_date=2026-09-01', $this->headers())
            ->assertStatus(422);

        $this->getJson('/api/v1/stores/' . self::STORE . '/schedule/insights?start_date=2026-09-28&end_date=2026-09-01', $this->headers())
            ->assertStatus(422);

        $this->getJson('/api/v1/stores/99999-00001/schedule/insights', $this->headers())
            ->assertNotFound();
    }
}
