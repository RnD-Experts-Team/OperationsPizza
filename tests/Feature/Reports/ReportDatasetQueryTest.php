<?php

namespace Tests\Feature\Reports;

use App\Models\Employee;
use App\Models\Store;
use App\Reports\Contract\ReportDataset;
use App\Reports\ReportDatasets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesAuthServer;
use Tests\TestCase;

/**
 * The report dataset contract routes end to end, through the real auth
 * middleware with pizzasys faked.
 */
class ReportDatasetQueryTest extends TestCase
{
    use FakesAuthServer, RefreshDatabase;

    private const URL = '/api/report-datasets/operations.actual_shifts/query';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeAuthServer();
        // The fake answers to any service name; the catalog reports the configured one.
        config(['services.auth_server.service_name' => 'Operations']);

        Store::query()->create(['id' => 1, 'store_number' => '03759-00001', 'name' => 'Downtown']);
        Store::query()->create(['id' => 2, 'store_number' => '03759-00002', 'name' => 'Uptown']);

        foreach ([501 => 'Marco', 502 => 'Lena'] as $id => $first) {
            Employee::query()->create(['id' => $id, 'first_name' => $first, 'last_name' => 'Rossi', 'active' => true]);
        }

        $this->actualShift(1, 501, '2026-10-21', 480, 'worked');
        $this->actualShift(1, 501, '2026-10-21', 120, 'worked');
        $this->actualShift(1, 502, '2026-10-22', 300, 'absent');
        $this->actualShift(2, 502, '2026-10-21', 360, 'worked');
        $this->actualShift(1, 501, '2026-10-30', 60, 'worked');
        DB::table('actual_shifts')->where('duration_minutes', 300)->update(['deleted_at' => now()]);
    }

    private function actualShift(int $store, int $employee, string $date, int $minutes, string $reviewState): void
    {
        DB::table('actual_shifts')->insert([
            'store_id' => $store,
            'employee_id' => $employee,
            'shift_date' => $date,
            'start_time' => '10:00:00',
            'end_time' => '18:00:00',
            'starts_at_utc' => "{$date} 15:00:00",
            'ends_at_utc' => "{$date} 23:00:00",
            'duration_minutes' => $minutes,
            'review_state' => $reviewState,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function body(array $overrides = []): array
    {
        return array_replace([
            'stores' => ['03759-00001'],
            'date_from' => '2026-10-21',
            'date_to' => '2026-10-27',
            'columns' => ['store', 'date', 'employee'],
            'group_by' => ['store', 'date', 'employee'],
            'aggregates' => [['column' => 'duration_minutes', 'fn' => 'sum', 'alias' => 'duration_minutes']],
            'limit' => 50,
        ], $overrides);
    }

    public function test_the_catalog_names_the_service_and_lists_every_dataset(): void
    {
        $response = $this->getJson('/api/report-datasets', $this->headers())
            ->assertOk()
            ->assertJsonPath('service', 'Operations')
            ->assertJsonPath('contract_version', ReportDataset::CONTRACT_VERSION)
            ->assertJsonPath('contract_sha1', ReportDataset::contractSha1());

        $this->assertSame(
            [
                'operations.actual_shifts', 'operations.scheduled_shifts', 'operations.time_off',
                'operations.employee_skills', 'operations.clock_segments', 'operations.availability',
            ],
            array_column($response->json('datasets'), 'key'),
        );
        $this->assertSame(['store', 'date', 'employee'], $response->json('datasets.0.grain'));
        $this->assertTrue($response->json('datasets.0.dated'));
        $this->assertArrayNotHasKey('max_days', $response->json('datasets.0'));

        // The snapshot datasets are not dated and have no date in the grain.
        foreach ([3, 5] as $i) {
            $this->assertFalse($response->json("datasets.{$i}.dated"));
            $this->assertSame(['store', 'employee'], $response->json("datasets.{$i}.grain"));
        }
        $this->assertTrue($response->json('datasets.4.dated'));
        $this->assertSame('/report-datasets/operations.actual_shifts/query', $response->json('datasets.0.path'));
    }

    public function test_every_dataset_has_a_literal_query_route(): void
    {
        foreach (ReportDatasets::DATASETS as $dataset) {
            $this->assertNotNull(
                app('router')->getRoutes()->match(
                    \Illuminate\Http\Request::create('/api/report-datasets/'.$dataset::KEY.'/query', 'POST')
                ),
            );
        }
    }

    public function test_a_grouped_query_with_a_store_filter_sums_by_store_date_and_employee(): void
    {
        $this->postJson(self::URL, $this->body(), $this->headers())
            ->assertOk()
            ->assertJson([
                'columns' => [
                    ['key' => 'store', 'type' => 'string'],
                    ['key' => 'date', 'type' => 'date'],
                    ['key' => 'employee', 'type' => 'integer'],
                    ['key' => 'duration_minutes', 'type' => 'integer'],
                ],
                // Store 2, the soft-deleted absence and the shift outside the period are left out.
                'rows' => [['03759-00001', '2026-10-21', 501, 600]],
                'next_cursor' => null,
                'truncated' => false,
                'meta' => ['row_count' => 1, 'contract_version' => 1],
            ]);

        // pizzasys judged this exact route with the store codes of the body.
        Http::assertSent(fn (Request $request) => ($request['path'] ?? null) === self::URL
            && $request['method'] === 'POST'
            && $request['store_context']['body']['stores'] === ['03759-00001']);
    }

    public function test_an_unknown_column_is_a_422(): void
    {
        $this->postJson(self::URL, $this->body(['columns' => ['store', 'hourly_rate']]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonPath('errors.columns', ['Unknown column hourly_rate.']);
    }

    public function test_a_long_period_is_answered(): void
    {
        $this->postJson(self::URL, $this->body(['date_from' => '2020-01-01', 'date_to' => '2027-03-01']), $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', [
                ['03759-00001', '2026-10-21', 501, 600],
                ['03759-00001', '2026-10-30', 501, 60],
            ]);
    }

    public function test_a_dated_dataset_without_a_period_is_a_422(): void
    {
        $this->postJson(self::URL, $this->body(['date_from' => null, 'date_to' => null]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_from');
    }

    public function test_empty_stores_means_no_store_filter(): void
    {
        $this->postJson(self::URL, $this->body(['stores' => [], 'columns' => ['store'], 'group_by' => ['store']]), $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', [['03759-00001', 600], ['03759-00002', 360]]);
    }

    public function test_scheduled_shifts_count_assigned_slots_and_minutes_per_employee(): void
    {
        DB::table('humanity_positions')->insert([
            'humanity_position_id' => 'POS1', 'name' => 'Kitchen', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $shift = fn (string $date, int $minutes) => DB::table('shifts')->insertGetId([
            'store_id' => 1, 'humanity_position_id' => 'POS1', 'shift_date' => $date,
            'start_time' => '10:00:00', 'end_time' => '18:00:00',
            'starts_at_utc' => "{$date} 15:00:00", 'ends_at_utc' => "{$date} 23:00:00",
            'duration_minutes' => $minutes, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $assign = fn (int $shiftId, int $employee, string $status) => DB::table('shift_assignments')->insert([
            'shift_id' => $shiftId, 'employee_id' => $employee, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $morning = $shift('2026-10-21', 240);
        $evening = $shift('2026-10-21', 300);
        $assign($morning, 501, 'assigned');
        $assign($evening, 501, 'assigned');
        $assign($evening, 502, 'assigned');
        $assign($morning, 502, 'released');

        $this->postJson('/api/report-datasets/operations.scheduled_shifts/query', $this->body([
            'columns' => ['store', 'date', 'employee', 'position'],
            'group_by' => ['store', 'date', 'employee', 'position'],
            'aggregates' => [
                ['column' => 'scheduled_minutes', 'fn' => 'sum', 'alias' => 'scheduled_minutes'],
                ['column' => 'slots', 'fn' => 'count', 'alias' => 'slots'],
            ],
        ]), $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', [
                ['03759-00001', '2026-10-21', 501, 'Kitchen', 540, 2],
                ['03759-00001', '2026-10-21', 502, 'Kitchen', 300, 1],
            ]);
    }

    public function test_time_off_is_counted_by_store_first_day_and_employee(): void
    {
        $leave = fn (?int $store, int $employee, string $start, string $type) => DB::table('time_off')->insert([
            'store_id' => $store, 'employee_id' => $employee, 'start_date' => $start, 'end_date' => $start,
            'type' => $type, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $leave(1, 501, '2026-10-22', 'sick');
        $leave(1, 501, '2026-10-22', 'sick');
        $leave(2, 502, '2026-10-22', 'pto');
        $leave(null, 502, '2026-10-22', 'pto');

        $this->postJson('/api/report-datasets/operations.time_off/query', $this->body([
            'columns' => ['store', 'date', 'employee', 'type'],
            'group_by' => ['store', 'date', 'employee', 'type'],
            'aggregates' => [['column' => 'count', 'fn' => 'count', 'alias' => 'count']],
        ]), $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', [['03759-00001', '2026-10-22', 501, 'sick', 2]]);
    }

    public function test_employee_skills_ignore_the_period_and_list_ratings_per_employee_and_skill(): void
    {
        DB::table('employees')->where('id', 502)->update(['active' => false]);
        Employee::query()->create(['id' => 503, 'first_name' => 'Nina', 'last_name' => 'Bianchi', 'active' => true]);

        // Marco works at both stores, Nina at store 2 only; Lena is inactive.
        foreach ([[501, '03759-00001'], [501, '03759-00002'], [502, '03759-00001'], [503, '03759-00002']] as [$employee, $store]) {
            DB::table('employee_store')->insert([
                'employee_id' => $employee, 'store_number' => $store, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $skillIds = DB::table('skills')->pluck('id', 'name');
        $rate = fn (int $employee, string $skill, int $rating, string $at) => DB::table('employee_skills')->insert([
            'employee_id' => $employee, 'skill_id' => $skillIds[$skill], 'rating' => $rating,
            'created_at' => $at, 'updated_at' => $at,
        ]);
        $rate(501, 'Dough', 5, '2026-01-05 10:00:00');
        $rate(501, 'Register', 3, '2026-02-10 10:00:00');
        $rate(502, 'Dough', 1, '2026-01-05 10:00:00');
        $rate(503, 'Dough', 4, '2026-03-01 10:00:00');

        $query = fn (array $overrides = []) => $this->postJson('/api/report-datasets/operations.employee_skills/query', array_replace([
            'stores' => ['03759-00001'],
            // Far from every rating: a snapshot answers the same regardless.
            'date_from' => '2020-01-01',
            'date_to' => '2020-01-02',
            'columns' => ['store', 'employee', 'employee_name', 'skill', 'rating', 'rated_on'],
            'limit' => 50,
        ], $overrides), $this->headers());

        // The inactive Lena and the other store's Nina are left out.
        $query()->assertOk()->assertJsonPath('rows', [
            ['03759-00001', 501, 'Marco Rossi', 'Dough', 5, '2026-01-05'],
            ['03759-00001', 501, 'Marco Rossi', 'Register', 3, '2026-02-10'],
        ]);

        // No period at all is fine too.
        $query(['date_from' => null, 'date_to' => null])->assertOk()->assertJsonPath('meta.row_count', 2);

        // An employee at several stores carries the same rating under each of them.
        $query(['stores' => []])->assertOk()->assertJsonPath('meta.row_count', 5);

        // Ratings summarised per skill at one store.
        $query([
            'stores' => ['03759-00002'],
            'columns' => ['skill'],
            'aggregates' => [
                ['column' => 'rating', 'fn' => 'avg', 'alias' => 'avg_rating'],
                ['column' => 'rating', 'fn' => 'min', 'alias' => 'min_rating'],
                ['column' => 'rating', 'fn' => 'max', 'alias' => 'max_rating'],
                ['column' => 'employee', 'fn' => 'count_distinct', 'alias' => 'people'],
            ],
            'filters' => [['column' => 'rating', 'op' => 'gte', 'value' => 3]],
        ])->assertOk()->assertJsonPath('rows', [
            ['Dough', 4.5, 4, 5, 2],
            ['Register', 3, 3, 3, 1],
        ]);
    }

    public function test_clock_segments_sum_minutes_by_store_local_date_employee_and_job_code(): void
    {
        DB::table('tcp_job_codes')->insert([
            'tcp_job_code_id' => '37590101', 'description' => 'Crew Member', 'store_number' => '03759-00001',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $segment = fn (int $n, int $store, int $employee, string $in, ?int $minutes) => DB::table('tcp_work_segments')->insert([
            'tcp_work_segment_id' => "seg-{$n}", 'employee_id' => $employee, 'store_id' => $store,
            'tcp_employee_id' => "t{$employee}", 'tcp_job_code_id' => '37590101', 'origin' => 'punch',
            'time_in' => $in, 'time_out' => $minutes === null ? null : $in,
            'starts_at_utc' => $in, 'duration_minutes' => $minutes,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $segment(1, 1, 501, '2026-10-21 10:00:00', 240);
        $segment(2, 1, 501, '2026-10-21 15:00:00', 180);
        $segment(3, 1, 501, '2026-10-22 23:00:00', null);
        $segment(4, 2, 502, '2026-10-21 10:00:00', 999);
        $segment(5, 1, 502, '2026-10-26 10:00:00', 999);
        DB::table('tcp_work_segments')->where('tcp_work_segment_id', 'seg-5')->update(['deleted_at' => now()]);

        $this->postJson('/api/report-datasets/operations.clock_segments/query', $this->body([
            'columns' => ['store', 'date', 'employee', 'job_code'],
            'group_by' => ['store', 'date', 'employee', 'job_code'],
            'aggregates' => [
                ['column' => 'minutes', 'fn' => 'sum', 'alias' => 'minutes'],
                ['column' => 'count', 'fn' => 'count', 'alias' => 'segments'],
            ],
        ]), $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', [
                ['03759-00001', '2026-10-21', 501, 'Crew Member', 420, 2],
                ['03759-00001', '2026-10-22', 501, 'Crew Member', null, 1],
            ]);
    }

    public function test_availability_sums_window_minutes_per_employee_and_weekday_ignoring_the_period(): void
    {
        Employee::query()->create(['id' => 503, 'first_name' => 'Nina', 'last_name' => 'Bianchi', 'active' => true]);
        foreach ([[501, '03759-00001'], [502, '03759-00001'], [503, '03759-00002']] as [$employee, $store]) {
            DB::table('employee_store')->insert([
                'employee_id' => $employee, 'store_number' => $store, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $day = function (int $employee, string $weekday, string $type, array $windows): void {
            $id = DB::table('employee_availability_days')->insertGetId([
                'employee_id' => $employee, 'day_of_week' => $weekday, 'shift_type' => $type,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($windows as [$from, $to]) {
                DB::table('employee_availability_times')->insert([
                    'employee_availability_day_id' => $id, 'available_from' => $from, 'available_to' => $to,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        };
        $day(501, 'monday', 'AM', [['09:00:00', '13:30:00']]);   // 270
        $day(501, 'monday', 'PM', [['17:00:00', '02:00:00']]);   // past midnight: 540
        $day(501, 'tuesday', 'OP', []);                           // no hours: all day, 1440
        $day(502, 'monday', 'AM', [['08:00:00', '12:00:00']]);   // 240
        $day(503, 'monday', 'AM', [['08:00:00', '12:00:00']]);   // another store

        $this->postJson('/api/report-datasets/operations.availability/query', $this->body([
            'date_from' => null,
            'date_to' => null,
            'columns' => ['store', 'employee', 'weekday'],
            'group_by' => ['store', 'employee', 'weekday'],
            'aggregates' => [
                ['column' => 'available_minutes', 'fn' => 'sum', 'alias' => 'available_minutes'],
                ['column' => 'windows', 'fn' => 'count', 'alias' => 'windows'],
            ],
        ]), $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', [
                ['03759-00001', 501, 'monday', 810, 2],
                ['03759-00001', 501, 'tuesday', 1440, 1],
                ['03759-00001', 502, 'monday', 240, 1],
            ]);
    }

    public function test_stream_mode_sends_ndjson_columns_rows_and_the_done_line(): void
    {
        $page = $this->postJson(self::URL, $this->body(), $this->headers())->assertOk()->json();

        $response = $this->postJson(self::URL, $this->body(['stream' => true, 'limit' => null]), $this->headers())->assertOk();

        $this->assertStringStartsWith('application/x-ndjson', $response->headers->get('Content-Type'));
        $lines = array_map(fn (string $line) => json_decode($line, true), explode("\n", rtrim($response->streamedContent(), "\n")));
        $rows = array_slice($lines, 1, -1);

        $this->assertSame(['columns' => $page['columns']], $lines[0]);
        $this->assertNotEmpty($rows);
        $this->assertEqualsCanonicalizing($page['rows'], $rows);
        $this->assertSame(['done' => true, 'row_count' => count($rows)], end($lines));
    }

    public function test_a_stream_query_with_a_limit_is_a_422(): void
    {
        $this->postJson(self::URL, $this->body(['stream' => true, 'limit' => 10]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limit');
    }
}
