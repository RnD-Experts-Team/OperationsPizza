<?php

namespace Tests\Feature\Humanity;

use App\Jobs\ProcessBulkOperationJob;
use App\Models\Employee;
use App\Models\EmployeeStore;
use App\Models\HumanityLocation;
use App\Models\HumanityPositionMap;
use App\Models\ScheduleBulkOperation;
use App\Models\Shift;
use App\Models\Store;
use App\Services\Humanity\Exceptions\HumanityException;
use App\Services\Humanity\FakeHumanityClient;
use App\Services\Humanity\PendingShiftSyncService;
use App\Services\Reconciliation\HumanityShiftReconciler;
use App\Services\Scheduling\BulkOperationService;
use App\Services\Scheduling\ShiftWriteService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What happens when Humanity throttles us mid-schedule.
 *
 * Humanity's limit is unpublished and account-wide, so 38 stores publishing the
 * same week share one budget. These cover the two things that has to not do:
 * lose a manager's work, and degrade unevenly (one store with a full week while
 * another has no Monday).
 */
class ThrottleResilienceTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private FakeHumanityClient $humanity;

    protected function setUp(): void
    {
        parent::setUp();

        config(['humanity.writes_enabled' => true, 'humanity.requests_per_second' => 0]);

        $this->humanity = app(FakeHumanityClient::class);
        $this->store = $this->makeStore(1, '03759-00001', 'Downtown');
    }

    /** The API routes verify bearer tokens against the auth service. */
    private function fakeAuthServer(): void
    {
        config([
            'services.auth_server.base_url' => 'http://auth.test',
            'services.auth_server.verify_path' => '/api/v1/auth/token/verify',
            'services.auth_server.service_name' => 'operations-system',
            'services.auth_server.call_token' => 'service-token',
        ]);

        // Users are replicated from pizzasys, never created by a request —
        // the middleware 401s on a token whose user has not arrived yet.
        \App\Models\User::query()->firstOrCreate(
            ['id' => 9],
            ['name' => 'Dana', 'email' => 'dana@example.com'],
        );

        Http::preventStrayRequests();

        Http::fake(['auth.test/*' => Http::response([
            'active' => true,
            'subject_type' => 'user',
            'user' => ['id' => 9, 'name' => 'Dana', 'email' => 'dana@example.com'],
            'roles' => ['Store Manager'],
            'permissions' => [],
            'ext' => ['authorized' => true],
        ])]);
    }

    private function makeStore(int $id, string $number, string $name): Store
    {
        $store = Store::query()->create([
            'id' => $id,
            'store_number' => $number,
            'name' => $name,
            'timezone' => 'America/Chicago',
        ]);
        $store->settings();

        // One Humanity location per store — the column is unique, and in
        // reality each store maps to its own.
        $locationId = "LOC{$id}";
        $positionId = "POS{$id}";

        HumanityLocation::query()->create([
            'store_id' => $id,
            'humanity_location_id' => $locationId,
            'name' => $name,
        ]);
        HumanityPositionMap::query()->create([
            'store_id' => $id,
            'position_label' => null,
            'humanity_position_id' => $positionId,
            'is_default' => true,
        ]);

        $fake = app(FakeHumanityClient::class);
        $fake->seedLocation($locationId, $name);
        $fake->seedPosition($positionId, 'Kitchen', $locationId);

        $employeeId = 500 + $id;

        Employee::query()->create([
            'id' => $employeeId,
            'first_name' => "Emp{$id}",
            'last_name' => 'Test',
            'active' => true,
            'humanity_employee_id' => (string) (88000 + $employeeId),
        ]);

        EmployeeStore::query()->create([
            'employee_id' => $employeeId,
            'store_number' => $number,
            'store_id' => $id,
            'status' => 'hired',
            'active' => true,
        ]);

        app(FakeHumanityClient::class)->seedEmployee((string) (88000 + $employeeId));

        return $store;
    }

    private function makeShift(Store $store, string $date, string $start = '09:00', string $end = '17:00'): Shift
    {
        return app(ShiftWriteService::class)->create($store, [
            'employee_id' => 500 + (int) $store->id,
            'shift_date' => $date,
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }

    /** Drain an operation the way a queue worker would: one day per run. */
    private function drain(ScheduleBulkOperation $operation, int $maxSlices = 10): ScheduleBulkOperation
    {
        for ($i = 0; $i < $maxSlices; $i++) {
            app(ProcessBulkOperationJob::class, ['operationId' => $operation->id])
                ->handle(app(ShiftWriteService::class));

            $operation = $operation->fresh();

            if (!in_array($operation->status, [
                ScheduleBulkOperation::STATUS_QUEUED,
                ScheduleBulkOperation::STATUS_PROCESSING,
            ], true)) {
                break;
            }
        }

        return $operation;
    }

    // ------------------------------------------------------------- day ordering

    public function test_bulk_items_are_ordered_by_day_with_that_days_delete_before_its_create(): void
    {
        // A week already in place, so `replace` has something to delete.
        $this->makeShift($this->store, '2026-08-11');
        $this->makeShift($this->store, '2026-08-13');

        $operation = app(BulkOperationService::class)->createShifts($this->store, '2026-08-11', [
            ['employee_id' => 501, 'day_index' => 2, 'start_time' => '10:00', 'end_time' => '18:00'],
            ['employee_id' => 501, 'day_index' => 0, 'start_time' => '10:00', 'end_time' => '18:00'],
        ], 'replace', null);

        $plan = $operation->items()->orderBy('sequence')->get()
            ->map(fn ($item) => $item->shift_date->toDateString() . ':' . $item->action)
            ->all();

        // Chronological, and each day's delete precedes its own create. The
        // shape that must NOT appear is every delete first: a throttle between
        // the phases would leave the week deleted upstream and not rebuilt.
        $this->assertSame([
            '2026-08-11:delete',
            '2026-08-11:create',
            '2026-08-13:delete',
            '2026-08-13:create',
        ], $plan);
    }

    public function test_a_bulk_run_processes_one_day_per_slice(): void
    {
        $operation = app(BulkOperationService::class)->createShifts($this->store, '2026-08-11', [
            ['employee_id' => 501, 'day_index' => 0, 'start_time' => '09:00', 'end_time' => '17:00'],
            ['employee_id' => 501, 'day_index' => 1, 'start_time' => '09:00', 'end_time' => '17:00'],
            ['employee_id' => 501, 'day_index' => 2, 'start_time' => '09:00', 'end_time' => '17:00'],
        ], 'merge', null);

        // One run, one day — the yield is what lets other stores interleave.
        app(ProcessBulkOperationJob::class, ['operationId' => $operation->id])
            ->handle(app(ShiftWriteService::class));

        $this->assertSame(1, Shift::query()->count());
        $this->assertSame(1, $operation->fresh()->succeeded_items);
        $this->assertSame(ScheduleBulkOperation::STATUS_PROCESSING, $operation->fresh()->status);

        $operation = $this->drain($operation);

        $this->assertSame(3, Shift::query()->count());
        $this->assertSame(ScheduleBulkOperation::STATUS_COMPLETED, $operation->status);
    }

    public function test_a_throttled_replace_never_leaves_the_week_empty(): void
    {
        $existing = $this->makeShift($this->store, '2026-08-11');

        $operation = app(BulkOperationService::class)->createShifts($this->store, '2026-08-11', [
            ['employee_id' => 501, 'day_index' => 0, 'start_time' => '10:00', 'end_time' => '18:00'],
        ], 'replace', null);

        // Throttle the create that follows the delete — the exact gap that
        // used to wipe a week and leave nothing in its place.
        $this->humanity->throttleNext('createShift');

        $this->drain($operation);

        // The replacement exists locally and is owed to Humanity, rather than
        // the day being simply gone.
        $replacement = Shift::query()->where('id', '!=', $existing->id)->first();

        $this->assertNotNull($replacement, 'The replacement shift must survive locally.');
        $this->assertSame(Shift::SYNC_PENDING, $replacement->sync_status);
        $this->assertNull($replacement->humanity_shift_id);
    }

    // -------------------------------------------------- local-first on throttle

    public function test_a_throttle_saves_the_shift_locally_and_marks_it_owed(): void
    {
        $this->humanity->throttleNext('createShift');

        $shift = $this->makeShift($this->store, '2026-08-11');

        // The manager is not blocked: a throttle is not their mistake and
        // redoing the action would not help.
        $this->assertSame(Shift::SYNC_PENDING, $shift->sync_status);
        $this->assertNull($shift->humanity_shift_id);
        $this->assertNotNull($shift->sync_next_attempt_at);
        $this->assertSame(1, $shift->assignments()->count());
    }

    public function test_a_non_throttle_failure_rejects_and_writes_nothing(): void
    {
        $this->humanity->failNext('createShift', new HumanityException('Position no longer exists'));

        try {
            $this->makeShift($this->store, '2026-08-11');
            $this->fail('A non-throttle Humanity failure must not be swallowed.');
        } catch (HumanityException $e) {
            // Expected: the manager has to see it and fix it.
        }

        // No orphan. Saving this locally would diverge on something a retry
        // can never resolve.
        $this->assertSame(0, Shift::query()->count());
    }

    public function test_the_reconciler_never_deletes_a_shift_awaiting_sync(): void
    {
        $this->humanity->throttleNext('createShift');
        $shift = $this->makeShift($this->store, '2026-08-11');

        // Older than the 120s grace window the reconciler otherwise relies on.
        $shift->forceFill(['created_at' => CarbonImmutable::now()->subHour()])->save();

        $report = app(HumanityShiftReconciler::class)->reconcile(
            $this->store,
            CarbonImmutable::parse('2026-08-10'),
            CarbonImmutable::parse('2026-08-17'),
            dryRun: false,
        );

        // Humanity legitimately does not have it yet. Deleting here would
        // destroy the manager's work precisely because the account was busy.
        $this->assertNotNull($shift->fresh(), 'A pending shift must survive reconciliation.');
        $this->assertSame(0, $report->deleted);
    }

    // ------------------------------------------------------------- the sweep

    public function test_the_sweep_drains_the_earliest_day_across_every_store(): void
    {
        $storeTwo = $this->makeStore(2, '03759-00002', 'Uptown');

        // Store 1 owes Monday and Wednesday; store 2 owes Monday.
        foreach ([[$this->store, '2026-08-13'], [$this->store, '2026-08-11'], [$storeTwo, '2026-08-11']] as [$store, $date]) {
            $this->humanity->throttleNext('createShift');
            $this->makeShift($store, $date);
        }

        $this->assertSame(3, Shift::query()->where('sync_status', Shift::SYNC_PENDING)->count());

        // Past the retry interval, so all three are due at once and ordering
        // is what decides which get through.
        $this->travel(7)->hours();

        $this->artisan('humanity:sync-pending-shifts', ['--limit' => 2])->assertSuccessful();

        $remaining = Shift::query()->where('sync_status', Shift::SYNC_PENDING)->get();

        // Both Mondays went first — across stores, not store by store. The
        // outcome being prevented is one store holding a full week while
        // another has no first day.
        $this->assertCount(1, $remaining);
        $this->assertSame('2026-08-13', $remaining->first()->shift_date->toDateString());
    }

    public function test_the_sweep_skips_a_pass_entirely_while_the_account_is_in_cooldown(): void
    {
        $this->humanity->throttleNext('createShift');
        $shift = $this->makeShift($this->store, '2026-08-11');

        app(\App\Services\Humanity\HumanityRateLimiter::class)->recordThrottle();

        $this->artisan('humanity:sync-pending-shifts')->assertSuccessful();

        // Still owed, and — crucially — no retry attempt was spent. Only four
        // exist before the shift is parked for a human.
        $this->assertSame(Shift::SYNC_PENDING, $shift->fresh()->sync_status);
        $this->assertSame(0, $shift->fresh()->sync_attempts);
    }

    public function test_an_owed_shift_is_not_retried_before_its_scheduled_time(): void
    {
        $this->humanity->throttleNext('createShift');
        $shift = $this->makeShift($this->store, '2026-08-11');

        // The sweep runs every few minutes, but each shift carries its own
        // next-attempt time — otherwise a backlog would re-hammer Humanity on
        // every tick and burn all four attempts within the hour.
        $this->artisan('humanity:sync-pending-shifts')->assertSuccessful();

        $this->assertSame(Shift::SYNC_PENDING, $shift->fresh()->sync_status);
    }

    public function test_the_sweep_pushes_an_owed_shift_once_its_retry_falls_due(): void
    {
        $this->humanity->throttleNext('createShift');
        $shift = $this->makeShift($this->store, '2026-08-11');

        $this->travel(7)->hours();

        $this->artisan('humanity:sync-pending-shifts')->assertSuccessful();

        $shift = $shift->fresh();

        $this->assertSame(Shift::SYNC_SYNCED, $shift->sync_status);
        $this->assertNotNull($shift->humanity_shift_id);
        $this->assertNull($shift->sync_next_attempt_at);
    }

    // ------------------------------------------------------- the retry schedule

    public function test_a_retry_waits_six_hours_or_until_midnight_whichever_is_sooner(): void
    {
        $sync = app(PendingShiftSyncService::class);

        // Mid-morning: six hours arrives long before midnight does.
        $this->assertSame(
            '2026-08-11 15:00:00',
            $sync->nextAttemptAt(CarbonImmutable::parse('2026-08-11 09:00:00'))->format('Y-m-d H:i:s'),
        );

        // Late evening: the new day arrives first. If the undocumented limit
        // turns out to be a daily quota, midnight is when capacity returns.
        $this->assertSame(
            '2026-08-12 00:00:00',
            $sync->nextAttemptAt(CarbonImmutable::parse('2026-08-11 23:00:00'))->format('Y-m-d H:i:s'),
        );
    }

    public function test_a_shift_parks_for_a_human_after_four_throttled_attempts(): void
    {
        $this->humanity->throttleNext('createShift');
        $shift = $this->makeShift($this->store, '2026-08-11');

        $sync = app(PendingShiftSyncService::class);

        for ($attempt = 1; $attempt <= app(PendingShiftSyncService::class)->maxAttempts(); $attempt++) {
            $this->humanity->throttleNext('createShift');
            $sync->syncOne($shift->fresh());
        }

        $shift = $shift->fresh();

        // A full day of retries has not moved it, so waiting longer will not
        // either. Parked rather than retried forever, and never silently
        // dropped: it exists locally but not in Humanity.
        $this->assertSame(Shift::SYNC_PARKED, $shift->sync_status);
        $this->assertNotNull($shift->sync_parked_at);
        $this->assertNull($shift->sync_next_attempt_at);
    }

    public function test_a_non_throttle_error_parks_immediately_rather_than_burning_a_day(): void
    {
        $this->humanity->throttleNext('createShift');
        $shift = $this->makeShift($this->store, '2026-08-11');

        $this->humanity->failNext('createShift', new HumanityException('Position no longer exists'));
        app(PendingShiftSyncService::class)->syncOne($shift->fresh());

        // Waiting cannot fix a bad mapping, so there is no point spending four
        // attempts across 24 hours to discover that.
        $this->assertSame(Shift::SYNC_PARKED, $shift->fresh()->sync_status);
    }

    public function test_the_week_payload_exposes_sync_status_so_the_ui_can_flag_it(): void
    {
        $this->humanity->throttleNext('createShift');
        $this->makeShift($this->store, '2026-08-11');

        $this->fakeAuthServer();

        $response = $this->getJson(
            '/api/v1/stores/03759-00001/schedule/week?week_start=2026-08-11',
            ['Authorization' => 'Bearer 1|test-token', 'Accept' => 'application/json'],
        );

        $shift = $response->assertOk()->json('data.shifts.0');

        // The shift is real and saved, but staff cannot see it in Humanity yet
        // — the UI has to be able to tell those apart.
        $this->assertSame(Shift::SYNC_PENDING, $shift['sync_status']);
        $this->assertNull($shift['humanity_shift_id']);
    }

    // ----------------------------------------------------------------- recovery

    public function test_retry_failed_resumes_an_operation_whose_items_are_all_pending(): void
    {
        $operation = app(BulkOperationService::class)->createShifts($this->store, '2026-08-11', [
            ['employee_id' => 501, 'day_index' => 0, 'start_time' => '09:00', 'end_time' => '17:00'],
        ], 'merge', null);

        // The state a throttle-killed run leaves behind: failed operation,
        // items still pending. retryFailed used to count only `failed`, see
        // zero, and return 200 having re-dispatched nothing.
        $operation->update(['status' => ScheduleBulkOperation::STATUS_FAILED]);

        $this->fakeAuthServer();

        $response = $this->postJson(
            "/api/v1/stores/03759-00001/schedule/bulk/{$operation->id}/retry-failed",
            [],
            ['Authorization' => 'Bearer 1|test-token', 'Accept' => 'application/json'],
        );

        $response->assertStatus(202);
        $this->assertSame(ScheduleBulkOperation::STATUS_QUEUED, $operation->fresh()->status);
    }
}
