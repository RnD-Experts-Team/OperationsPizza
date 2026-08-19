<?php

namespace Tests\Feature\Tcp;

use App\Models\ActualShift;
use App\Models\Employee;
use App\Models\EmployeeStore;
use App\Models\HumanityLocation;
use App\Models\HumanityPosition;
use App\Models\HumanityPositionMap;
use App\Models\Store;
use App\Services\Tcp\Dto\TcpWorkSegment;
use App\Services\Tcp\FakeTcpClient;
use App\Services\Tcp\TcpClockService;
use App\Services\Tcp\TcpWorkSegmentSync;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * TCP allows 2500 requests per 24 hours for the WHOLE service — roughly 104 an
 * hour across every store, job and manager. These tests assert the CALL COUNT,
 * not just the behaviour, because a correct sync that spends the daily budget
 * by lunchtime is still broken.
 */
class TcpQuotaEfficiencyTest extends TestCase
{
    use RefreshDatabase;

    private FakeTcpClient $tcp;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tcp.driver' => 'fake', 'tcp.environment' => 'sandbox', 'tcp.writes_enabled' => true]);

        $store = Store::query()->create(['id' => 1, 'store_number' => 'S1', 'name' => 'Downtown']);
        $store->settings();

        HumanityLocation::query()->create([
            'store_id' => 1, 'humanity_location_id' => 'LOC1', 'timezone' => 'America/Chicago',
        ]);
        HumanityPosition::query()->create([
            'humanity_position_id' => 'JOB1', 'humanity_location_id' => 'LOC1', 'name' => 'Kitchen',
        ]);
        HumanityPositionMap::query()->create([
            'store_id' => 1, 'position_label' => null, 'humanity_position_id' => 'JOB1', 'is_default' => true,
        ]);

        // A realistic roster: enough people that a naive sync would need
        // several chunked requests.
        foreach (range(1, 45) as $i) {
            Employee::query()->create([
                'id' => 500 + $i,
                'first_name' => "E{$i}",
                'last_name' => 'Test',
                'active' => true,
                'tcp_employee_id' => (string) (500 + $i),
            ]);

            EmployeeStore::query()->create([
                'employee_id' => 500 + $i,
                'store_number' => 'S1',
                'store_id' => 1,
                'active' => true,
            ]);
        }

        $this->tcp = app(FakeTcpClient::class);

        // These employees have no positions, so job codes resolve through the
        // account-wide fallback — which must exist in the synced catalog.
        \App\Models\TcpJobCode::query()->create([
            'tcp_job_code_id' => 'JOB1',
            'description' => 'Regular',
            'store_number' => null,
            'clockable' => true,
            'is_active' => true,
        ]);
        config(['tcp.default_job_code' => 'JOB1']);
    }

    private function store(): Store
    {
        return Store::query()->findOrFail(1);
    }

    private function callsTo(string $op): int
    {
        return count(array_filter($this->tcp->calls, fn (array $c) => $c['op'] === $op));
    }

    public function test_a_quiet_sync_round_costs_a_single_request(): void
    {
        $sync = app(TcpWorkSegmentSync::class);

        // First pass establishes the cursor.
        $sync->sync($this->store());

        $this->tcp->calls = [];
        // Nothing has changed since.
        $this->tcp->calculationChanges = [];

        $sync->sync($this->store());

        // The whole point: ask "did anything happen?" once and stop. With 45
        // employees a naive sync would page them 20 at a time instead.
        $this->assertSame(1, $this->callsTo('listCalculationChanges'));
        $this->assertSame(0, $this->callsTo('listWorkSegments'));
    }

    public function test_a_busy_round_fetches_only_the_employees_that_changed(): void
    {
        $sync = app(TcpWorkSegmentSync::class);
        $sync->sync($this->store());

        $this->tcp->seedSegment(new TcpWorkSegment(
            id: '9001', employeeId: '501', jobCodeId: 'JOB1',
            timeIn: CarbonImmutable::now()->subHours(9)->format('Y-m-d\TH:i:s'),
            timeOut: CarbonImmutable::now()->subHour()->format('Y-m-d\TH:i:s'),
            updatedOn: CarbonImmutable::now()->format('Y-m-d\TH:i:s'),
        ));

        $this->tcp->calls = [];
        // Only one person's card moved.
        $this->tcp->calculationChanges = ['501'];

        $sync->sync($this->store());

        // One change feed + ONE segment fetch for the single affected employee.
        // Without the narrowing this would be ceil(45/20) = 3 fetches.
        $this->assertSame(1, $this->callsTo('listCalculationChanges'));
        $this->assertSame(1, $this->callsTo('listWorkSegments'));
        $this->assertSame(1, ActualShift::count());
    }

    public function test_a_bulk_recalculation_falls_back_to_everyone(): void
    {
        $sync = app(TcpWorkSegmentSync::class);
        $sync->sync($this->store());

        $this->tcp->calls = [];
        // TCP says "everything changed" — narrowing would silently drop work.
        $this->tcp->allEmployeesChanged = true;
        $this->tcp->calculationChanges = [];

        $sync->sync($this->store());

        $this->assertGreaterThanOrEqual(1, $this->callsTo('listWorkSegments'));
    }

    public function test_the_change_feed_is_shared_across_stores(): void
    {
        // The feed is account-wide, so N stores must not cost N identical calls.
        $second = Store::query()->create(['id' => 2, 'store_number' => 'S2']);
        $second->settings();
        HumanityLocation::query()->create([
            'store_id' => 2, 'humanity_location_id' => 'LOC2', 'timezone' => 'America/Chicago',
        ]);

        Employee::query()->create([
            'id' => 900, 'first_name' => 'Z', 'last_name' => 'Test', 'active' => true, 'tcp_employee_id' => '900',
        ]);
        EmployeeStore::query()->create([
            'employee_id' => 900, 'store_number' => 'S2', 'store_id' => 2, 'active' => true,
        ]);

        $sync = app(TcpWorkSegmentSync::class);
        $sync->sync($this->store());
        $sync->sync($second);

        $this->tcp->calls = [];
        $this->tcp->calculationChanges = [];

        $sync->sync($this->store());
        $sync->sync($second);

        // Two stores, one call — the second read the cached answer.
        $this->assertSame(1, $this->callsTo('listCalculationChanges'));
    }

    public function test_a_full_sync_bypasses_the_change_feed(): void
    {
        $sync = app(TcpWorkSegmentSync::class);
        $sync->sync($this->store());

        $this->tcp->calls = [];
        $this->tcp->calculationChanges = [];

        // --full is for backfills and repairs: it must not be silently skipped
        // just because TCP reports no changes.
        $sync->sync($this->store(), null, null, full: true);

        $this->assertSame(0, $this->callsTo('listCalculationChanges'));
        $this->assertGreaterThanOrEqual(1, $this->callsTo('listWorkSegments'));
    }

    // ------------------------------------------------------------------ punches

    public function test_a_clock_in_then_out_costs_one_request_each(): void
    {
        $employee = Employee::query()->find(501);
        $clock = app(TcpClockService::class);

        $clock->clockIn($this->store(), $employee);

        // The first punch may verify state; after that we know it exactly.
        $this->tcp->calls = [];

        $clock->clockOut($this->store(), $employee);

        // No lookup: the clock-in response already told us the state.
        $this->assertSame(0, $this->callsTo('listWorkSegments'));
        $this->assertSame(1, $this->callsTo('punch'));
    }

    public function test_a_backdated_correction_still_verifies_against_tcp(): void
    {
        $employee = Employee::query()->find(501);
        $clock = app(TcpClockService::class);

        $clock->clockIn($this->store(), $employee);
        $this->tcp->calls = [];

        // Our cache describes "now", not last week — so a correction must not
        // trust it.
        try {
            $clock->clockIn(
                $this->store(),
                $employee,
                CarbonImmutable::now()->subDays(7)
            );
        } catch (\Throwable) {
            // Either outcome is fine; the point is that it checked.
        }

        $this->assertSame(1, $this->callsTo('listWorkSegments'));
    }

    public function test_a_stale_clock_state_expires_rather_than_blocking_a_shift(): void
    {
        $employee = Employee::query()->find(501);
        $clock = app(TcpClockService::class);

        $clock->clockIn($this->store(), $employee);

        // Someone clocks out at a physical clock; we never hear about it.
        $this->tcp->segments = [];
        Cache::flush();

        $this->tcp->calls = [];

        // Being wrong here would refuse a real shift, so the cache must expire
        // and fall back to asking TCP.
        $clock->clockIn($this->store(), $employee, CarbonImmutable::now());

        $this->assertSame(1, $this->callsTo('listWorkSegments'));
    }
}
