<?php

namespace Tests\Feature\Scheduling;

use App\Models\Employee;
use App\Models\EmployeeStore;
use App\Models\HumanityLocation;
use App\Models\HumanityPosition;
use App\Models\HumanityPositionMap;
use App\Models\HumanitySyncLog;
use App\Models\OperationsOutboxEvent;
use App\Models\Shift;
use App\Models\Store;
use App\Services\Humanity\Exceptions\HumanityException;
use App\Services\Humanity\FakeHumanityClient;
use App\Services\Scheduling\Exceptions\SchedulingException;
use App\Services\Scheduling\ShiftWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftWriteServiceTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Employee $employee;
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

        HumanityLocation::query()->create([
            'store_id' => 1,
            'humanity_location_id' => 'LOC1',
            'name' => 'Downtown',
        ]);

        HumanityPosition::query()->create([
            'humanity_position_id' => 'POS1',
            'humanity_location_id' => 'LOC1',
            'name' => 'Kitchen',
        ]);

        HumanityPositionMap::query()->create([
            'store_id' => 1,
            'position_id' => null,
            'humanity_position_id' => 'POS1',
            'is_default' => true,
        ]);

        $this->employee = Employee::query()->create([
            'id' => 501,
            'first_name' => 'Marco',
            'last_name' => 'Rossi',
            'active' => true,
            'current_status' => 'hired',
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

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'employee_id' => 501,
            'shift_date' => '2026-08-04',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'label' => 'Morning',
            'shift_type' => 'morning',
        ], $overrides);
    }

    public function test_it_writes_to_humanity_then_mirrors_locally(): void
    {
        $shift = app(ShiftWriteService::class)->create($this->store, $this->payload());

        // Humanity first...
        $this->assertCount(1, $this->humanity->shifts);
        // ...then the mirror, keyed by what Humanity returned.
        $this->assertNotNull($shift->humanity_shift_id);
        // PHP coerces numeric array keys to int, so compare as strings.
        $this->assertSame((string) array_key_first($this->humanity->shifts), $shift->humanity_shift_id);
        $this->assertSame('2026-08-04', $shift->shift_date->toDateString());
        $this->assertSame(480, $shift->duration_minutes);
        $this->assertSame('POS1', $shift->humanity_position_id);
        $this->assertSame(Shift::ORIGIN_OPERATIONS, $shift->origin);
        $this->assertSame(501, $shift->assignments->first()->employee_id);
        $this->assertNotNull($shift->fresh()->humanity_hash);
    }

    public function test_a_humanity_failure_persists_nothing(): void
    {
        $this->humanity->failNext('createShift');

        try {
            app(ShiftWriteService::class)->create($this->store, $this->payload());
            $this->fail('Expected the Humanity failure to propagate.');
        } catch (HumanityException) {
            // expected
        }

        // The whole point of calling Humanity before opening a transaction:
        // a failure leaves no orphan shift and no event.
        $this->assertSame(0, Shift::count());
        $this->assertSame(0, OperationsOutboxEvent::count());

        $log = HumanitySyncLog::sole();
        $this->assertSame('failed', $log->status);
        $this->assertNotNull($log->error_message);
    }

    public function test_a_successful_write_records_a_sync_log_and_an_outbox_event(): void
    {
        app(ShiftWriteService::class)->create($this->store, $this->payload());

        $log = HumanitySyncLog::sole();
        $this->assertSame('succeeded', $log->status);
        $this->assertSame('create', $log->operation);
        // Written BEFORE the call, which is what makes a timeout recoverable.
        $this->assertNotNull($log->idempotency_key);

        $event = OperationsOutboxEvent::sole();
        $this->assertSame('operations.v1.shift.created', $event->subject);
        $this->assertNull($event->published_at);
    }

    public function test_an_overnight_shift_is_stored_with_the_correct_utc_span(): void
    {
        $shift = app(ShiftWriteService::class)->create($this->store, $this->payload([
            'start_time' => '22:00',
            'end_time' => '02:00',
        ]));

        $this->assertTrue($shift->crosses_midnight);
        $this->assertSame(240, $shift->duration_minutes);
        // shift_date stays the START date.
        $this->assertSame('2026-08-04', $shift->shift_date->toDateString());
        $this->assertSame('2026-08-05 07:00:00', $shift->ends_at_utc->toDateTimeString());
    }

    public function test_a_shift_ending_at_midnight_is_not_zero_length(): void
    {
        // The store closes at 00:00, so this is the ordinary closing shift.
        $shift = app(ShiftWriteService::class)->create($this->store, $this->payload([
            'start_time' => '17:00',
            'end_time' => '00:00',
        ]));

        $this->assertSame(420, $shift->duration_minutes);
        $this->assertTrue($shift->crosses_midnight);
    }

    public function test_an_overlapping_shift_is_rejected(): void
    {
        app(ShiftWriteService::class)->create($this->store, $this->payload());

        try {
            app(ShiftWriteService::class)->create($this->store, $this->payload([
                'start_time' => '16:00',
                'end_time' => '20:00',
            ]));
            $this->fail('Expected a conflict.');
        } catch (SchedulingException $e) {
            $this->assertSame('SHIFT_CONFLICT', $e->errorCode);
            $this->assertSame(409, $e->statusCode);
        }

        $this->assertSame(1, Shift::count());
    }

    public function test_an_overnight_conflict_is_detected_across_the_date_boundary(): void
    {
        app(ShiftWriteService::class)->create($this->store, $this->payload([
            'start_time' => '22:00',
            'end_time' => '06:00',
        ]));

        // Next calendar day, but the same real hours. Wall-clock comparison
        // (what the frontend does) would miss this entirely.
        $this->expectException(SchedulingException::class);

        app(ShiftWriteService::class)->create($this->store, $this->payload([
            'shift_date' => '2026-08-05',
            'start_time' => '02:00',
            'end_time' => '09:00',
        ]));
    }

    public function test_force_overrides_a_conflict(): void
    {
        app(ShiftWriteService::class)->create($this->store, $this->payload());

        app(ShiftWriteService::class)->create($this->store, $this->payload([
            'start_time' => '16:00',
            'end_time' => '20:00',
            'force' => true,
        ]));

        $this->assertSame(2, Shift::count());
    }

    public function test_an_unmapped_store_fails_before_calling_humanity(): void
    {
        HumanityLocation::query()->delete();

        try {
            app(ShiftWriteService::class)->create($this->store, $this->payload());
            $this->fail('Expected STORE_NOT_MAPPED.');
        } catch (SchedulingException $e) {
            $this->assertSame('STORE_NOT_MAPPED', $e->errorCode);
            $this->assertSame(422, $e->statusCode);
        }

        $this->assertCount(0, $this->humanity->shifts);
    }

    public function test_an_employee_from_another_store_is_rejected(): void
    {
        EmployeeStore::query()->where('employee_id', 501)->update(['store_number' => 'OTHER']);

        $this->expectException(SchedulingException::class);

        app(ShiftWriteService::class)->create($this->store, $this->payload());
    }

    public function test_update_writes_through_and_refreshes_the_mirror(): void
    {
        $shift = app(ShiftWriteService::class)->create($this->store, $this->payload());
        $humanityId = $shift->humanity_shift_id;

        $updated = app(ShiftWriteService::class)->update($this->store, $shift, [
            'start_time' => '10:00',
            'end_time' => '18:00',
        ]);

        $this->assertSame('10:00:00', $updated->start_time);
        $this->assertSame(480, $updated->duration_minutes);
        // Same Humanity shift, moved — not a delete and recreate.
        $this->assertSame($humanityId, $updated->humanity_shift_id);
        $this->assertSame('10:00', $this->humanity->shifts[$humanityId]->startTime);
    }

    public function test_delete_removes_it_from_humanity_first(): void
    {
        $shift = app(ShiftWriteService::class)->create($this->store, $this->payload());

        app(ShiftWriteService::class)->delete($this->store, $shift);

        $this->assertCount(0, $this->humanity->shifts);
        $this->assertSame(0, Shift::count());
        $this->assertSame(1, Shift::withTrashed()->count());
    }

    public function test_a_failed_remote_delete_leaves_the_local_shift_intact(): void
    {
        $shift = app(ShiftWriteService::class)->create($this->store, $this->payload());

        $this->humanity->failNext('deleteShift');

        try {
            app(ShiftWriteService::class)->delete($this->store, $shift);
            $this->fail('Expected the delete to propagate.');
        } catch (HumanityException) {
            // expected
        }

        // Soft-deleting locally here would produce a shift invisible to
        // managers but still live for the employee — the worst divergence.
        $this->assertSame(1, Shift::count());
        $this->assertCount(1, $this->humanity->shifts);
    }

    public function test_writes_are_refused_while_the_write_flag_is_off(): void
    {
        // The guard that stops anyone touching live Humanity data before the
        // employee backfill has run.
        config(['humanity.writes_enabled' => false]);

        $this->expectException(HumanityException::class);

        app(ShiftWriteService::class)->create($this->store, $this->payload());
    }
}
