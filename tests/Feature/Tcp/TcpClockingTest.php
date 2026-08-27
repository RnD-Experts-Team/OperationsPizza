<?php

namespace Tests\Feature\Tcp;

use App\Models\ActualShift;
use App\Models\Employee;
use App\Models\EmployeeClockState;
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

        // Still open — nothing to mirror yet, same rule the bulk sync uses.
        $this->assertSame(0, ActualShift::count());

        $this->clock()->clockOut($this->store, $this->employee, CarbonImmutable::parse('2026-08-06 17:00', 'America/Chicago'));

        $actual = ActualShift::sole();
        $this->assertSame(501, $actual->employee_id);
        $this->assertSame('timeclock', $actual->source);
        $this->assertSame(480, $actual->duration_minutes);
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
        $this->assertSame('9001', $actual->tcp_work_segment_id);
        $this->assertSame(480, $actual->duration_minutes);
        // No planned shift to match, so it is ad-hoc coverage.
        $this->assertSame(ActualShift::STATUS_ADDED, $actual->status);
    }

    public function test_an_open_segment_is_not_imported(): void
    {
        // Someone still on the clock would otherwise appear as a shift whose
        // end time keeps moving.
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

        $this->assertSame(0, $stats['imported']);
        $this->assertSame(1, $stats['skipped']);
        $this->assertSame(0, ActualShift::count());
    }

    public function test_a_missed_punch_is_flagged_rather_than_hidden(): void
    {
        $this->seedClosedSegment('9003', '2026-08-06T09:00:00', '2026-08-06T17:00:00', ['missedOut' => true]);

        app(TcpWorkSegmentSync::class)->sync($this->store, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'));

        // The recorded time is a system default, not something the employee
        // did — a manager has to see that.
        $this->assertTrue(ActualShift::sole()->has_missed_punch);
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

    public function test_a_punch_records_the_clock_state_in_the_database(): void
    {
        $this->clock()->clockIn($this->store, $this->employee, CarbonImmutable::parse('2026-08-06 09:00', 'America/Chicago'));

        $state = EmployeeClockState::query()->where('employee_id', 501)->first();

        $this->assertNotNull($state, 'A punch must leave a durable record, not just a cache entry.');
        $this->assertSame(EmployeeClockState::STATUS_CLOCKED_IN, $state->status);
        $this->assertTrue($state->isClockedIn());
        $this->assertSame('501', $state->tcp_employee_id);
        $this->assertSame(1, (int) $state->store_id);
        $this->assertNotNull($state->clock_in_at);
        $this->assertNotNull($state->openSegment());
    }

    public function test_clocking_out_flips_the_persisted_state(): void
    {
        $this->clock()->clockIn($this->store, $this->employee, CarbonImmutable::parse('2026-08-06 09:00', 'America/Chicago'));
        $this->clock()->clockOut($this->store, $this->employee, CarbonImmutable::parse('2026-08-06 17:00', 'America/Chicago'));

        $state = EmployeeClockState::query()->where('employee_id', 501)->first();

        $this->assertSame(EmployeeClockState::STATUS_CLOCKED_OUT, $state->status);
        $this->assertFalse($state->isClockedIn());
        // A closed segment leaves nothing "open" to report.
        $this->assertNull($state->clock_in_at);
        $this->assertNull($state->openSegment());
        // One row per employee, upserted — not an append-only log.
        $this->assertSame(1, EmployeeClockState::query()->where('employee_id', 501)->count());
    }

    public function test_a_break_is_distinguishable_from_being_clocked_out(): void
    {
        $this->clock()->clockIn($this->store, $this->employee, CarbonImmutable::parse('2026-08-06 09:00', 'America/Chicago'));
        $this->clock()->breakStart($this->store, $this->employee, 0, CarbonImmutable::parse('2026-08-06 12:00', 'America/Chicago'));

        $state = EmployeeClockState::query()->where('employee_id', 501)->first();

        // TCP closes the segment for a break, so only WE know it was a break.
        $this->assertSame(EmployeeClockState::STATUS_ON_BREAK, $state->status);
        $this->assertFalse($state->isClockedIn());
        $this->assertNotNull($state->break_started_at);
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

    public function test_a_stale_persisted_state_is_reverified_against_tcp(): void
    {
        $this->clock()->clockIn($this->store, $this->employee, CarbonImmutable::parse('2026-08-06 09:00', 'America/Chicago'));

        Cache::flush();

        // Age the row past the trust window. Persisting state must not make a
        // stale answer any longer-lived than the cache-only version allowed —
        // someone can still punch at a physical clock or in TCP's own app.
        EmployeeClockState::query()->where('employee_id', 501)->update([
            'last_synced_at' => CarbonImmutable::now()->subSeconds(
                (int) config('tcp.clock_state_ttl_seconds', 900) + 60
            ),
        ]);

        $before = count(array_filter($this->tcp->calls, fn ($c) => $c['op'] === 'listWorkSegments'));

        $this->clock()->currentSegment($this->employee);

        $after = count(array_filter($this->tcp->calls, fn ($c) => $c['op'] === 'listWorkSegments'));

        $this->assertGreaterThan($before, $after, 'A stale row must be re-verified against TCP.');
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
