<?php

namespace Tests\Feature\Tcp;

use App\Models\ActualShift;
use App\Models\Employee;
use App\Models\TcpWorkSegment as WorkSegmentRow;
use App\Models\EmployeeStore;
use App\Models\HumanityLocation;
use App\Models\HumanityPosition;
use App\Models\HumanityPositionMap;
use App\Models\HumanitySyncLog;
use App\Models\Store;
use App\Services\Scheduling\Exceptions\SchedulingException;
use App\Services\Tcp\Dto\TcpWorkSegment;
use App\Services\Tcp\Exceptions\TcpException;
use App\Services\Tcp\Exceptions\TcpRateLimitException;
use App\Services\Tcp\FakeTcpClient;
use App\Services\Tcp\TcpClockService;
use App\Services\Tcp\TcpRateLimiter;
use App\Services\Tcp\TcpWorkSegmentSync;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TcpClockingTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Employee $employee;
    private FakeTcpClient $tcp;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tcp.driver' => 'fake', 'tcp.environment' => 'sandbox', 'tcp.writes_enabled' => true]);

        $this->store = Store::query()->create([
            'id' => 1,
            'store_number' => '03759-00001',
            'name' => 'Downtown',
        ]);
        $this->store->settings();

        HumanityLocation::query()->create([
            'store_id' => 1,
            'humanity_location_id' => 'LOC1',
            'name' => 'Downtown',
            'timezone' => 'America/Chicago',
        ]);
        HumanityPosition::query()->create([
            'humanity_position_id' => 'JOB1',
            'humanity_location_id' => 'LOC1',
            'name' => 'Kitchen',
        ]);
        HumanityPositionMap::query()->create([
            'store_id' => 1,
            'position_label' => null,
            'humanity_position_id' => 'JOB1',
            'is_default' => true,
        ]);

        // The TCP job-code catalog, as tcp:sync-catalog would mirror it: the
        // per-store code's description starts with the position label, and the
        // store attribution came from the "Restaurant Id" custom field.
        \App\Models\TcpJobCode::query()->create([
            'tcp_job_code_id' => 'JOB1',
            'description' => 'Kitchen - 3759-01',
            'store_number' => '03759-00001',
            'clockable' => true,
            'is_active' => true,
        ]);

        $this->employee = Employee::query()->create([
            'id' => 501,
            'first_name' => 'Marco',
            'last_name' => 'Rossi',
            'active' => true,
            'tcp_employee_id' => '501',
            'position_label' => 'Kitchen',
        ]);

        EmployeeStore::query()->create([
            'employee_id' => 501,
            'store_number' => '03759-00001',
            'store_id' => 1,
            'status' => 'hired',
            'active' => true,
        ]);

        $this->tcp = app(FakeTcpClient::class);
        $this->tcp->seedEmployee('501', ['firstName' => 'Marco', 'lastName' => 'Rossi']);
        $this->tcp->seedJobCode('JOB1', 'Kitchen');
    }

    private function clock(): TcpClockService
    {
        return app(TcpClockService::class);
    }

    public function test_clock_in_opens_a_segment_in_tcp(): void
    {
        $segment = $this->clock()->clockIn(
            $this->store,
            $this->employee,
            CarbonImmutable::parse('2026-08-06 09:00', 'America/Chicago')
        );

        $this->assertTrue($segment->isOpen());
        $this->assertSame('501', $segment->employeeId);
        $this->assertSame('JOB1', $segment->jobCodeId);
        $this->assertNotNull($this->tcp->openSegmentFor('501'));
    }

    public function test_clock_status_does_not_hit_tcp_on_every_read(): void
    {
        $this->clock()->clockIn($this->store, $this->employee, CarbonImmutable::parse('2026-08-06 09:00', 'America/Chicago'));

        // The punch response already told us the current segment, so a poll
        // right after one must not spend a call from the 2500/day quota.
        $before = count(array_filter($this->tcp->calls, fn ($c) => $c['op'] === 'listWorkSegments'));

        $this->assertNotNull($this->clock()->currentSegment($this->employee));
        $this->assertNotNull($this->clock()->currentSegment($this->employee));
        $this->assertNotNull($this->clock()->currentSegment($this->employee));

        $after = count(array_filter($this->tcp->calls, fn ($c) => $c['op'] === 'listWorkSegments'));

        $this->assertSame($before, $after, 'clock-status must be served from cache, not TCP.');
    }

    public function test_clock_status_reflects_our_own_punch_immediately(): void
    {
        $this->clock()->clockIn($this->store, $this->employee, CarbonImmutable::parse('2026-08-06 09:00', 'America/Chicago'));
        $this->assertNotNull($this->clock()->currentSegment($this->employee));

        // A cache that outlived our own clock-out would report someone still
        // on the clock — worse than the call it saves.
        $this->clock()->clockOut($this->store, $this->employee, CarbonImmutable::parse('2026-08-06 17:00', 'America/Chicago'));

        $this->assertNull($this->clock()->currentSegment($this->employee));
    }

    public function test_clock_out_closes_the_open_segment(): void
    {
        $this->clock()->clockIn($this->store, $this->employee, CarbonImmutable::parse('2026-08-06 09:00', 'America/Chicago'));

        $segment = $this->clock()->clockOut($this->store, $this->employee, CarbonImmutable::parse('2026-08-06 17:00', 'America/Chicago'));

        $this->assertFalse($segment->isOpen());
        $this->assertSame('2026-08-06T17:00:00', $segment->timeOut);
        // Closing must amend the same segment, not open a second one.
        $this->assertCount(1, $this->tcp->segments);
    }

    public function test_a_missing_id_on_the_punch_response_is_backfilled_via_a_live_lookup(): void
    {
        // CONFIRMED live 2026-08-26: TCP's own punch response never carries
        // the segment's real id — the fake defaults to this shape. Without
        // the backfill, this id would be '' and every downstream use
        // (logging, actual_shifts matching) would be working with a blank.
        $segment = $this->clock()->clockIn($this->store, $this->employee, CarbonImmutable::parse('2026-08-06 09:00', 'America/Chicago'));

        $this->assertNotSame('', $segment->id);
        $this->assertTrue($segment->isOpen());
    }

    public function test_clock_out_automatically_mirrors_into_actual_shifts(): void
    {
        $this->clock()->clockIn($this->store, $this->employee, CarbonImmutable::parse('2026-08-06 09:00', 'America/Chicago'));

        // The shift exists straight away, flagged in progress. It used to be
        // discarded until clock-out, which is why "who is on the clock" needed
        // a table of its own.
        $inProgress = ActualShift::sole();
        $this->assertTrue($inProgress->is_open);
        $this->assertNull($inProgress->end_time, 'a shift still being worked has no end, and must not invent one');

        $this->clock()->clockOut($this->store, $this->employee, CarbonImmutable::parse('2026-08-06 17:00', 'America/Chicago'));

        $actual = ActualShift::sole();
        $this->assertSame(501, $actual->employee_id);
        $this->assertSame('timeclock', $actual->source);
        $this->assertSame(480, $actual->duration_minutes);
        $this->assertFalse($actual->is_open);
        $this->assertSame('17:00:00', $actual->end_time);
    }

    public function test_a_stale_cached_clocked_in_state_is_verified_live_before_refusing(): void
    {
        // Simulates real-world drift: the cache says "clocked in" (from an
        // earlier bug, or a change made directly in TCP outside this
        // system), but TCP genuinely has no open segment. The refusal must
        // not be trusted blindly — it should self-correct via a live check.
        Cache::put('tcp:clockstate:501', true, 900);

        $segment = $this->clock()->clockIn($this->store, $this->employee, CarbonImmutable::parse('2026-08-06 09:00', 'America/Chicago'));

        $this->assertTrue($segment->isOpen());
    }

    public function test_a_double_clock_in_is_refused_before_calling_tcp(): void
    {
        $this->clock()->clockIn($this->store, $this->employee, CarbonImmutable::parse('2026-08-06 09:00', 'America/Chicago'));

        try {
            $this->clock()->clockIn($this->store, $this->employee, CarbonImmutable::parse('2026-08-06 10:00', 'America/Chicago'));
            $this->fail('Expected ALREADY_CLOCKED_IN.');
        } catch (SchedulingException $e) {
            $this->assertSame('ALREADY_CLOCKED_IN', $e->errorCode);
            $this->assertSame(409, $e->statusCode);
        }

        $this->assertCount(1, $this->tcp->segments);
    }

    public function test_clocking_out_without_clocking_in_is_refused(): void
    {
        $this->expectException(SchedulingException::class);

        $this->clock()->clockOut($this->store, $this->employee);
    }

    public function test_an_employee_with_no_tcp_link_cannot_clock_in(): void
    {
        Employee::query()->whereKey(501)->update(['tcp_employee_id' => null]);

        try {
            $this->clock()->clockIn($this->store, $this->employee->fresh());
            $this->fail('Expected EMPLOYEE_NOT_IN_TCP.');
        } catch (SchedulingException $e) {
            // Distinct from EMPLOYEE_NOT_SYNCED: that one self-heals over NATS,
            // this one needs the person created in TCP.
            $this->assertSame('EMPLOYEE_NOT_IN_TCP', $e->errorCode);
        }

        $this->assertCount(0, $this->tcp->segments);
    }

    public function test_a_break_closes_then_reopens_a_segment(): void
    {
        $this->clock()->clockIn($this->store, $this->employee, CarbonImmutable::parse('2026-08-06 09:00', 'America/Chicago'));

        // TCP models a break as timeOut then a fresh timeIn, not a flag.
        $this->clock()->breakStart($this->store, $this->employee, 0, CarbonImmutable::parse('2026-08-06 12:00', 'America/Chicago'));
        $this->assertNull($this->tcp->openSegmentFor('501'));

        $this->clock()->breakEnd($this->store, $this->employee, CarbonImmutable::parse('2026-08-06 12:30', 'America/Chicago'));
        $this->assertNotNull($this->tcp->openSegmentFor('501'));

        $this->assertCount(2, $this->tcp->segments);
    }

    public function test_a_punch_is_recorded_in_the_sync_log(): void
    {
        $this->clock()->clockIn($this->store, $this->employee, CarbonImmutable::parse('2026-08-06 09:00', 'America/Chicago'));

        $log = HumanitySyncLog::query()->where('entity_type', 'tcp_punch')->sole();

        $this->assertSame('succeeded', $log->status);
        $this->assertSame('clock_in', $log->operation);
        $this->assertSame(501, $log->entity_id);
    }

    public function test_a_failed_punch_is_recorded_and_rethrown(): void
    {
        $this->tcp->failNext('punch');

        try {
            $this->clock()->clockIn($this->store, $this->employee);
            $this->fail('Expected the TCP failure to propagate.');
        } catch (TcpException) {
            // expected
        }

        $this->assertSame('failed', HumanitySyncLog::query()->where('entity_type', 'tcp_punch')->sole()->status);
    }

    public function test_writes_are_refused_while_the_flag_is_off(): void
    {
        config(['tcp.writes_enabled' => false]);

        $this->expectException(TcpException::class);

        $this->clock()->clockIn($this->store, $this->employee);
    }

    public function test_a_store_with_no_matching_job_code_cannot_clock_in(): void
    {
        // No catalog row for this store's position → nothing to punch with.
        // (Job codes resolve from tcp_job_codes, never from the Humanity
        // position mapping — a Humanity position id is not a TCP job code.)
        \App\Models\TcpJobCode::query()->delete();

        try {
            $this->clock()->clockIn($this->store, $this->employee);
            $this->fail('Expected JOB_CODE_NOT_MAPPED.');
        } catch (SchedulingException $e) {
            $this->assertSame('JOB_CODE_NOT_MAPPED', $e->errorCode);
        }
    }

    // ------------------------------------------------------------ worked hours

    private function seedClosedSegment(string $id, string $in, string $out, array $extra = []): void
    {
        $this->tcp->seedSegment(new TcpWorkSegment(
            id: $id,
            employeeId: '501',
            jobCodeId: 'JOB1',
            timeIn: $in,
            timeOut: $out,
            actualTimeIn: $extra['actualIn'] ?? $in,
            actualTimeOut: $extra['actualOut'] ?? $out,
            missedInPunch: $extra['missedIn'] ?? false,
            missedOutPunch: $extra['missedOut'] ?? false,
            updatedOn: $extra['updatedOn'] ?? CarbonImmutable::now()->format('Y-m-d\TH:i:s'),
        ));
    }

    public function test_worked_segments_become_actual_shifts(): void
    {
        $this->seedClosedSegment('9001', '2026-08-06T09:00:00', '2026-08-06T17:00:00');

        $stats = app(TcpWorkSegmentSync::class)->sync(
            $this->store,
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-31')
        );

        $this->assertSame(1, $stats['imported']);

        $actual = ActualShift::sole();
        $this->assertSame(501, $actual->employee_id);
        $this->assertSame('timeclock', $actual->source);
        $this->assertSame('9001', $actual->segments->sole()->tcp_work_segment_id);
        $this->assertSame(480, $actual->duration_minutes);
        // No planned shift to match, so it is ad-hoc coverage.
        $this->assertSame(ActualShift::VARIANCE_UNPLANNED, $actual->time_variance);
        // A punch nobody has looked at yet: recorded, not reviewed.
        $this->assertSame(ActualShift::REVIEW_UNREVIEWED, $actual->review_state);
    }

    /**
     * The headline case: a punch made somewhere else entirely.
     *
     * Nobody touched our API here — the segment is simply sitting in TCP, as it
     * would be after somebody clocked in at a physical clock or in TCP's own
     * app. The sync has to find it, and the person has to show as on the clock.
     *
     * This used to be impossible in two separate ways: the sync discarded open
     * segments, and the table that held "who is on the clock" was only ever
     * written by our own punch endpoints.
     */
    public function test_a_punch_made_outside_this_system_shows_up_on_the_clock(): void
    {
        $this->tcp->seedSegment(new TcpWorkSegment(
            id: '9002',
            employeeId: '501',
            jobCodeId: 'JOB1',
            timeIn: '2026-08-06T09:00:00',
            timeOut: null,
        ));

        $stats = app(TcpWorkSegmentSync::class)->sync(
            $this->store,
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-31')
        );

        $this->assertSame(1, $stats['imported']);

        $segment = WorkSegmentRow::query()->sole();
        $this->assertTrue($segment->isOpen());
        $this->assertSame(
            WorkSegmentRow::ORIGIN_DISCOVERED,
            $segment->origin,
            'found in TCP, not punched here'
        );

        // And it is visible without a single further TCP call.
        $before = count($this->tcp->calls);
        $this->assertNotNull($this->clock()->currentSegment($this->employee));
        $this->assertCount(1, $this->clock()->onTheClock($this->store));
        $this->assertSame($before, count($this->tcp->calls));

        $rollup = ActualShift::sole();
        $this->assertTrue($rollup->is_open);
    }

    public function test_a_missed_punch_is_flagged_rather_than_hidden(): void
    {
        $this->seedClosedSegment('9003', '2026-08-06T09:00:00', '2026-08-06T17:00:00', ['missedOut' => true]);

        app(TcpWorkSegmentSync::class)->sync($this->store, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'));

        // The recorded time is a system default, not something the employee
        // did — a manager has to see that.
        $this->assertTrue(WorkSegmentRow::query()->sole()->hasMissedPunch());
        $this->assertTrue(
            ActualShift::sole()->needs_attention,
            'and the shift above it has to surface, or nobody looks at the segment'
        );
    }

    public function test_resyncing_the_same_segment_updates_rather_than_duplicates(): void
    {
        $this->seedClosedSegment('9004', '2026-08-06T09:00:00', '2026-08-06T17:00:00');

        $sync = app(TcpWorkSegmentSync::class);
        $sync->sync($this->store, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'), full: true);

        // A manager edits the punch in TCP.
        $this->seedClosedSegment('9004', '2026-08-06T09:00:00', '2026-08-06T18:00:00');
        $stats = $sync->sync($this->store, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'), full: true);

        $this->assertSame(1, $stats['updated']);
        $this->assertSame(1, ActualShift::count());
        $this->assertSame(540, ActualShift::sole()->duration_minutes);
    }

    public function test_the_delta_cursor_limits_what_is_re_read(): void
    {
        $sync = app(TcpWorkSegmentSync::class);

        $this->seedClosedSegment('9005', '2026-08-06T09:00:00', '2026-08-06T17:00:00', [
            'updatedOn' => CarbonImmutable::now()->subDays(2)->format('Y-m-d\TH:i:s'),
        ]);

        $sync->sync($this->store, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'));
        $this->assertSame(1, ActualShift::count());

        // Second pass: the cursor is now set, and this segment has not been
        // touched since — TCP's updatedOn filter should exclude it entirely.
        $stats = $sync->sync($this->store, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'));

        $this->assertSame(0, $stats['segments']);
    }

    public function test_segments_for_unlinked_employees_are_counted_not_invented(): void
    {
        $this->tcp->seedSegment(new TcpWorkSegment(
            id: '9006',
            employeeId: '99999',
            jobCodeId: 'JOB1',
            timeIn: '2026-08-06T09:00:00',
            timeOut: '2026-08-06T17:00:00',
        ));

        $stats = app(TcpWorkSegmentSync::class)->sync(
            $this->store,
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-31')
        );

        // Creating an employee here would fight the hiring replication.
        $this->assertSame(0, $stats['imported']);
        $this->assertSame(0, ActualShift::count());
    }

    // -------------------------------------------------------------- rate limit

    public function test_the_rate_limiter_reserves_headroom_for_interactive_traffic(): void
    {
        config(['tcp.rate_limit.per_day' => 10, 'tcp.rate_limit.reserve_per_day' => 4]);

        $limiter = app(TcpRateLimiter::class);

        // Background work may use 6 of 10.
        for ($i = 0; $i < 6; $i++) {
            $limiter->hit();
        }

        $this->assertSame(0, $limiter->remainingToday());

        try {
            $limiter->hit();
            $this->fail('Expected the background budget to be exhausted.');
        } catch (TcpRateLimitException $e) {
            $this->assertTrue($e->isDailyCap);
        }

        // ...but a manager clocking someone in can still get through.
        $limiter->hit(interactive: true);
        $this->assertSame(7, $limiter->usedToday());
    }

    public function test_a_sync_is_skipped_rather_than_half_run_when_the_quota_is_low(): void
    {
        config(['tcp.rate_limit.per_day' => 1, 'tcp.rate_limit.reserve_per_day' => 1]);

        $this->seedClosedSegment('9007', '2026-08-06T09:00:00', '2026-08-06T17:00:00');

        $stats = app(TcpWorkSegmentSync::class)->sync(
            $this->store,
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-31')
        );

        // A partial sync leaves a misleading picture; skipping is honest.
        $this->assertSame(0, $stats['imported']);
        $this->assertSame(0, ActualShift::count());
    }

    // ------------------------------------------------ durable clock state

    public function test_a_punch_records_an_open_segment_in_the_database(): void
    {
        $this->clock()->clockIn($this->store, $this->employee, CarbonImmutable::parse('2026-08-06 09:00', 'America/Chicago'));

        $segment = WorkSegmentRow::query()->where('employee_id', 501)->sole();

        $this->assertTrue($segment->isOpen(), 'an open segment IS "on the clock" — there is no second table for it');
        $this->assertSame('501', $segment->tcp_employee_id);
        $this->assertSame(1, (int) $segment->store_id);
        $this->assertSame(WorkSegmentRow::ORIGIN_PUNCH, $segment->origin);
        $this->assertNotNull($segment->time_in);
    }

    public function test_clocking_out_closes_the_same_segment(): void
    {
        $this->clock()->clockIn($this->store, $this->employee, CarbonImmutable::parse('2026-08-06 09:00', 'America/Chicago'));
        $this->clock()->clockOut($this->store, $this->employee, CarbonImmutable::parse('2026-08-06 17:00', 'America/Chicago'));

        // Amended in place, not appended: TCP closes the segment it opened, and
        // so do we.
        $segment = WorkSegmentRow::query()->where('employee_id', 501)->sole();

        $this->assertFalse($segment->isOpen());
        $this->assertSame(480, $segment->duration_minutes);
        $this->assertNull($this->clock()->currentSegment($this->employee));
        $this->assertCount(0, $this->clock()->onTheClock($this->store));
    }

    /**
     * A break closes the worked segment in TCP, so mid-break the person is not
     * on the clock — which is exactly what TCP itself would report.
     *
     * The old `on_break` state existed only to remember that the gap was a break
     * rather than the end of a shift. Nothing needs to: a shift here is
     * continuous expected work, and the roll-up glues the two segments back
     * together on the gap rule regardless.
     */
    public function test_a_break_closes_the_segment_and_reopens_a_new_one(): void
    {
        $this->clock()->clockIn($this->store, $this->employee, CarbonImmutable::parse('2026-08-06 09:00', 'America/Chicago'));
        $this->clock()->breakStart($this->store, $this->employee, 0, CarbonImmutable::parse('2026-08-06 12:00', 'America/Chicago'));

        $this->assertNull($this->clock()->currentSegment($this->employee), 'a break closes the segment');

        $this->clock()->breakEnd($this->store, $this->employee, CarbonImmutable::parse('2026-08-06 12:30', 'America/Chicago'));

        $this->assertNotNull($this->clock()->currentSegment($this->employee));
        $this->assertSame(2, WorkSegmentRow::query()->where('employee_id', 501)->count());

        // Two segments, ONE shift. Writing them as two actual shifts is what hit
        // the unique constraint on shift_assignment_id and killed the sync run.
        $this->assertSame(1, ActualShift::count());
    }

    public function test_clock_status_survives_a_cache_flush_without_calling_tcp(): void
    {
        $this->clock()->clockIn($this->store, $this->employee, CarbonImmutable::parse('2026-08-06 09:00', 'America/Chicago'));

        // The whole point of persisting: losing the cache must not cost a call
        // against a 2500/day quota to rediscover what we already recorded.
        Cache::flush();

        $before = count(array_filter($this->tcp->calls, fn ($c) => $c['op'] === 'listWorkSegments'));

        $segment = $this->clock()->currentSegment($this->employee);

        $after = count(array_filter($this->tcp->calls, fn ($c) => $c['op'] === 'listWorkSegments'));

        $this->assertNotNull($segment, 'The durable row should still answer clock-status.');
        $this->assertTrue($segment->isOpen());
        $this->assertSame($before, $after, 'A cache flush must be served from the database, not TCP.');
    }

    /**
     * Somebody clocks out in TCP's own UI while our table still shows them on
     * the clock. The sync has to notice — that is the drift the old cache-and-
     * TTL arrangement could only ever paper over.
     */
    public function test_a_clock_out_made_in_tcp_is_picked_up_by_the_sync(): void
    {
        $this->clock()->clockIn($this->store, $this->employee, CarbonImmutable::parse('2026-08-06 09:00', 'America/Chicago'));
        $this->assertNotNull($this->clock()->currentSegment($this->employee));

        // A supervisor closes it directly in TCP. Nothing tells us.
        $open = $this->tcp->openSegmentFor('501');
        $this->tcp->segments[$open->id] = new TcpWorkSegment(
            id: $open->id,
            employeeId: '501',
            jobCodeId: 'JOB1',
            timeIn: $open->timeIn,
            timeOut: '2026-08-06T17:00:00',
        );

        app(TcpWorkSegmentSync::class)->sync(
            $this->store,
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-31')
        );

        $this->assertNull($this->clock()->currentSegment($this->employee), 'the sync must reconcile our picture with TCP');
        $this->assertSame(480, ActualShift::sole()->duration_minutes);
    }

    public function test_a_stored_segment_round_trips_intact(): void
    {
        $segment = new TcpWorkSegment(
            id: 'WS9',
            employeeId: '501',
            jobCodeId: 'JOB1',
            timeIn: '2026-08-06T09:00:00',
            timeOut: null,
            actualTimeIn: '2026-08-06T08:57:00',
            missedInPunch: true,
            breakLength: '30',
            shiftNotes: ['covered for Ana'],
        );

        $restored = TcpWorkSegment::fromArray($segment->toArray());

        $this->assertEquals($segment, $restored);
    }
}
