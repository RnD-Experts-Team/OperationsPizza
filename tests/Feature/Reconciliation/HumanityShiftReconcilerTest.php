<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Employee;
use App\Models\EmployeeStore;
use App\Models\HumanityLocation;
use App\Models\HumanityPosition;
use App\Models\HumanityPositionMap;
use App\Models\HumanitySyncLog;
use App\Models\Shift;
use App\Models\Store;
use App\Services\Humanity\Dto\HumanityShiftResult;
use App\Services\Humanity\FakeHumanityClient;
use App\Services\Reconciliation\HumanityShiftReconciler;
use App\Services\Scheduling\ShiftWriteService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HumanityShiftReconcilerTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private FakeHumanityClient $humanity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::query()->create([
            'id' => 1,
            'store_number' => '03759-00001',
            'name' => 'Downtown',
            'timezone' => 'America/Chicago',
        ]);
        $this->store->settings();

        HumanityLocation::query()->create(['store_id' => 1, 'humanity_location_id' => 'LOC1', 'name' => 'Downtown']);
        HumanityPosition::query()->create(['humanity_position_id' => 'POS1', 'humanity_location_id' => 'LOC1', 'name' => 'Kitchen']);
        HumanityPositionMap::query()->create(['store_id' => 1, 'position_id' => null, 'humanity_position_id' => 'POS1', 'is_default' => true]);

        Employee::query()->create([
            'id' => 501,
            'first_name' => 'Marco',
            'last_name' => 'Rossi',
            'active' => true,
            'humanity_employee_id' => '88213',
        ]);

        EmployeeStore::query()->create([
            'employee_id' => 501,
            'store_number' => '03759-00001',
            'store_id' => 1,
            'status' => 'hired',
            'active' => true,
        ]);

        $this->humanity = app(FakeHumanityClient::class);
        $this->humanity->seedLocation('LOC1');
        $this->humanity->seedPosition('POS1', 'Kitchen', 'LOC1');
        $this->humanity->seedEmployee('88213', ['eid' => '501']);

        config(['humanity.writes_enabled' => true]);
    }

    private function reconcile(bool $dryRun = false)
    {
        return app(HumanityShiftReconciler::class)->reconcile(
            $this->store,
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-31'),
            $dryRun
        );
    }

    private function createLocalShift(): Shift
    {
        return app(ShiftWriteService::class)->create($this->store, [
            'employee_id' => 501,
            'shift_date' => '2026-08-04',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'label' => 'Morning',
            'shift_type' => 'morning',
        ]);
    }

    public function test_an_unchanged_shift_is_left_alone(): void
    {
        $shift = $this->createLocalShift();
        $originalHash = $shift->fresh()->humanity_hash;

        $report = $this->reconcile();

        $this->assertSame(1, $report->unchanged);
        $this->assertSame(0, $report->updated);
        $this->assertSame(0, $report->deleted);
        $this->assertSame($originalHash, $shift->fresh()->humanity_hash);
        $this->assertNotNull($shift->fresh()->last_reconciled_at);
    }

    public function test_a_shift_created_directly_in_humanity_is_imported(): void
    {
        // This is what makes Humanity the real source of truth: a manager
        // using Humanity's own app must show up in our grid.
        $this->humanity->seedShift(new HumanityShiftResult(
            shiftId: '9001',
            positionId: 'POS1',
            locationId: 'LOC1',
            startDate: '2026-08-05',
            startTime: '12:00',
            endDate: '2026-08-05',
            endTime: '20:00',
            employeeIds: ['88213'],
            title: 'Made in Humanity',
        ));

        $report = $this->reconcile();

        $this->assertSame(1, $report->imported);

        $shift = Shift::query()->where('humanity_shift_id', '9001')->sole();
        $this->assertSame(Shift::ORIGIN_HUMANITY, $shift->origin);
        $this->assertSame(480, $shift->duration_minutes);
        $this->assertSame(501, $shift->assignments->first()->employee_id);
    }

    public function test_an_edit_made_in_humanity_overwrites_our_mirror(): void
    {
        $shift = $this->createLocalShift();
        $humanityId = $shift->humanity_shift_id;

        // Someone moved it in Humanity's UI.
        $existing = $this->humanity->shifts[$humanityId];
        $this->humanity->seedShift(new HumanityShiftResult(
            shiftId: $existing->shiftId,
            positionId: $existing->positionId,
            locationId: $existing->locationId,
            startDate: $existing->startDate,
            startTime: '11:00',
            endDate: $existing->endDate,
            endTime: '19:00',
            employeeIds: $existing->employeeIds,
            title: $existing->title,
        ));

        $report = $this->reconcile();

        $this->assertSame(1, $report->updated);

        $shift->refresh();
        $this->assertSame('11:00:00', $shift->start_time);
        $this->assertSame('19:00:00', $shift->end_time);
        $this->assertSame(Shift::ORIGIN_RECONCILER, $shift->origin);

        // The diff is recorded so a manager can see what moved and why.
        $log = HumanitySyncLog::query()->where('operation', 'reconcile')->sole();
        $this->assertArrayHasKey('start_time', $log->diff);
    }

    public function test_a_shift_deleted_in_humanity_is_soft_deleted_here(): void
    {
        $shift = $this->createLocalShift();

        // Age it past the grace window; a just-created shift is protected.
        $shift->forceFill(['created_at' => now()->subMinutes(10)])->saveQuietly();

        $this->humanity->shifts = [];

        $report = $this->reconcile();

        $this->assertSame(1, $report->deleted);
        $this->assertNull(Shift::find($shift->id));
        $this->assertNotNull(Shift::withTrashed()->find($shift->id));
    }

    public function test_a_freshly_created_shift_is_not_reaped_by_an_in_flight_pass(): void
    {
        $shift = $this->createLocalShift();

        // Simulate the race: our shift exists locally but the listing we got
        // predates it.
        $this->humanity->shifts = [];

        $report = $this->reconcile();

        $this->assertSame(0, $report->deleted);
        $this->assertSame(1, $report->skipped);
        $this->assertNotNull(Shift::find($shift->id));
    }

    public function test_a_dry_run_reports_without_changing_anything(): void
    {
        $shift = $this->createLocalShift();
        $shift->forceFill(['created_at' => now()->subMinutes(10)])->saveQuietly();
        $this->humanity->shifts = [];

        $report = $this->reconcile(dryRun: true);

        $this->assertSame(1, $report->deleted);
        // Reported, not performed — this is what makes the first production
        // run safe to look at.
        $this->assertNotNull(Shift::find($shift->id));
    }

    public function test_a_second_pass_is_skipped_while_one_is_running(): void
    {
        $lock = \Illuminate\Support\Facades\Cache::lock('humanity:recon:1', 300);
        $lock->get();

        try {
            $report = $this->reconcile();

            $this->assertNotEmpty($report->errors);
            $this->assertSame(0, $report->remoteSeen);
        } finally {
            $lock->release();
        }
    }

    public function test_a_remote_shift_for_an_unlinked_employee_still_imports_the_shift(): void
    {
        $this->humanity->seedShift(new HumanityShiftResult(
            shiftId: '9002',
            positionId: 'POS1',
            locationId: 'LOC1',
            startDate: '2026-08-06',
            startTime: '09:00',
            endDate: '2026-08-06',
            endTime: '17:00',
            employeeIds: ['99999'], // nobody we know
        ));

        $report = $this->reconcile();

        // The shift is real and belongs on the grid; we just can't attribute
        // it yet. Dropping it would hide live coverage from the manager.
        $this->assertSame(1, $report->imported);
        $shift = Shift::query()->where('humanity_shift_id', '9002')->sole();
        $this->assertCount(0, $shift->assignments);
    }
}
