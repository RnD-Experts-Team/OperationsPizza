<?php

namespace Tests\Feature\Tcp;

use App\Models\ActualShift;
use App\Models\Employee;
use App\Models\EmployeeStore;
use App\Models\HumanityLocation;
use App\Models\HumanityPosition;
use App\Models\HumanityPositionMap;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Store;
use App\Models\TcpJobCode;
use App\Models\TcpWorkSegment as WorkSegmentRow;
use App\Services\Tcp\Dto\TcpWorkSegment;
use App\Services\Tcp\FakeTcpClient;
use App\Services\Tcp\TcpWorkSegmentSync;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * How punched segments become the shifts a manager reviews.
 *
 * These cover the behaviour that made the old grain unworkable. `actual_shifts`
 * was 1:1 with a TCP segment, but punching out and back in closes one segment
 * and opens another — so a shift routinely arrives as several, overlap-matching
 * mapped every one of them to the same planned assignment, and that column is
 * unique. The second segment could not be written at all: a constraint violation
 * that aborted the store's sync run, or a swallowed log line when a punch
 * triggered it. No test covered it, because every sync test seeded exactly one
 * closed segment.
 */
class TcpRollupTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private Employee $employee;
    private FakeTcpClient $tcp;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tcp.driver' => 'fake', 'tcp.writes_enabled' => true]);

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
        TcpJobCode::query()->create([
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
        $this->tcp->seedEmployee('501');
        $this->tcp->seedJobCode('JOB1', 'Kitchen');

        // The fixtures are dated, and the reconciler's window is relative to
        // now — so without this the sweep looks at a fortnight that does not
        // contain them, and finds nothing to reconcile.
        CarbonImmutable::setTestNow('2026-08-07 08:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------- grouping

    /**
     * The bug this whole reshape exists for.
     *
     * Two segments, one planned assignment, and a unique column. Under the old
     * grain the second segment simply could not be written.
     */
    public function test_a_shift_split_by_a_punch_is_one_rollup_not_two(): void
    {
        $assignment = $this->plannedAssignment('09:00', '17:00');

        $this->seedSegment('A', '2026-08-06T09:00:00', '2026-08-06T12:00:00');
        $this->seedSegment('B', '2026-08-06T12:30:00', '2026-08-06T17:00:00');

        $this->sync();

        $rollup = ActualShift::sole();

        $this->assertSame(2, $rollup->segments()->count());
        $this->assertSame($assignment->id, $rollup->shift_assignment_id);
        $this->assertSame('09:00:00', $rollup->start_time);
        $this->assertSame('17:00:00', $rollup->end_time);

        // Worked time is the SUM of the segments, not the span: the half hour
        // off the clock is not paid.
        $this->assertSame(450, $rollup->duration_minutes);
    }

    public function test_a_gap_wider_than_the_threshold_is_a_separate_shift(): void
    {
        // A genuine split shift: in this morning, back this evening.
        $this->seedSegment('A', '2026-08-06T09:00:00', '2026-08-06T12:00:00');
        $this->seedSegment('B', '2026-08-06T17:00:00', '2026-08-06T21:00:00');

        $this->sync();

        $this->assertSame(2, ActualShift::count());
    }

    public function test_a_gap_inside_the_threshold_is_the_same_shift(): void
    {
        $this->seedSegment('A', '2026-08-06T09:00:00', '2026-08-06T12:00:00');
        $this->seedSegment('B', '2026-08-06T12:30:00', '2026-08-06T17:00:00');

        $this->sync();

        $this->assertSame(1, ActualShift::count());
    }

    /**
     * Grouping is ours — TCP has no concept of which segments form a shift — so
     * a manager's decision about it contradicts nothing TCP holds and must
     * survive the next pass. The times underneath still come from TCP.
     */
    public function test_a_pinned_grouping_is_not_re_derived(): void
    {
        $this->seedSegment('A', '2026-08-06T09:00:00', '2026-08-06T12:00:00');
        $this->seedSegment('B', '2026-08-06T12:30:00', '2026-08-06T17:00:00');
        $this->sync();

        // The manager splits them apart: these were two separate call-ins.
        $first = ActualShift::sole();
        $second = ActualShift::query()->create([
            'store_id' => 1,
            'employee_id' => 501,
            'shift_date' => '2026-08-06',
            'start_time' => '12:30:00',
            'end_time' => '17:00:00',
            'starts_at_utc' => '2026-08-06 17:30:00',
            'ends_at_utc' => '2026-08-06 22:00:00',
            'duration_minutes' => 270,
            'time_variance' => ActualShift::VARIANCE_UNPLANNED,
            'review_state' => ActualShift::REVIEW_UNREVIEWED,
            'grouping_pinned' => true,
        ]);

        WorkSegmentRow::query()->where('tcp_work_segment_id', 'B')
            ->update(['actual_shift_id' => $second->id]);
        $first->update(['grouping_pinned' => true]);

        $this->sync();

        $this->assertSame(2, ActualShift::count(), 'the gap rule must not undo a human decision');
        $this->assertSame('B', WorkSegmentRow::query()->where('actual_shift_id', $second->id)->value('tcp_work_segment_id'));
    }

    // --------------------------------------------------------- in progress

    public function test_a_shift_still_being_worked_has_no_end_and_accrues(): void
    {
        CarbonImmutable::setTestNow('2026-08-06 17:00:00');

        // 09:00 local = 14:00 UTC. Three hours ago.
        $this->seedSegment('A', '2026-08-06T09:00:00', null);
        $this->sync();

        $rollup = ActualShift::sole();

        $this->assertTrue($rollup->is_open);
        $this->assertNull($rollup->end_time, 'an end time here would be invented, and TCP owns the times');
        $this->assertSame(180, $rollup->duration_minutes);
        // Still running, so there is nothing to compare against a plan yet.
        $this->assertFalse($rollup->needs_attention);
    }

    /**
     * Somebody forgot to clock out. The shift must stop accruing and say so
     * rather than grow into a forty-hour figure — but it must NOT be given an
     * end time, because only a real punch or a correction in TCP can close it.
     */
    public function test_an_open_segment_past_the_cap_stops_accruing_and_flags(): void
    {
        config(['tcp.rollup.max_shift_hours' => 16]);

        CarbonImmutable::setTestNow('2026-08-08 09:00:00');

        // Clocked in two days ago and never out.
        $this->seedSegment('A', '2026-08-06T09:00:00', null);
        $this->sync();

        $rollup = ActualShift::sole();

        $this->assertSame(16 * 60, $rollup->duration_minutes, 'capped, not 48 hours');
        $this->assertTrue($rollup->needs_attention);
        $this->assertTrue($rollup->is_open);
        $this->assertNull($rollup->end_time);
        $this->assertNull(WorkSegmentRow::query()->sole()->time_out, 'the segment stays open — TCP owns that');
    }

    // ------------------------------------------------------- what is ours

    /**
     * The sync used to write TCP's shiftNotes into the same column a manager
     * typed into. TCP usually carries no shift note, so the next run silently
     * erased what they had written.
     */
    public function test_a_manager_note_survives_a_sync_that_carries_none(): void
    {
        $this->seedSegment('A', '2026-08-06T09:00:00', '2026-08-06T17:00:00');
        $this->sync();

        $rollup = ActualShift::sole();
        $rollup->update(['manager_note' => 'came in to cover Dani']);

        // TCP has nothing to say about this segment, and says it again.
        $this->sync();

        $this->assertSame('came in to cover Dani', $rollup->refresh()->manager_note);
    }

    public function test_a_sync_never_overwrites_a_human_verdict(): void
    {
        $this->seedSegment('A', '2026-08-06T09:00:00', '2026-08-06T17:00:00');
        $this->sync();

        $rollup = ActualShift::sole();
        $rollup->update(['review_state' => ActualShift::REVIEW_WORKED, 'reviewed_at' => now()]);

        $this->sync();

        $this->assertSame(ActualShift::REVIEW_WORKED, $rollup->refresh()->review_state);
    }

    // ------------------------------------------------------------- deletion

    /**
     * A delta cannot see a deletion — a voided segment simply stops being
     * returned, which is indistinguishable from "unchanged". Only a sweep can.
     */
    public function test_a_segment_voided_in_tcp_is_retired_and_resurfaces_the_shift(): void
    {
        $this->seedSegment('A', '2026-08-06T09:00:00', '2026-08-06T12:00:00');
        $this->seedSegment('B', '2026-08-06T12:30:00', '2026-08-06T17:00:00');
        $this->sync();

        $rollup = ActualShift::sole();
        $rollup->update([
            'review_state' => ActualShift::REVIEW_WORKED,
            'reviewed_at' => now()->subHour(),
            'needs_attention' => false,
        ]);
        $this->assertSame(450, $rollup->duration_minutes);

        // A supervisor voids the second punch in TCP.
        unset($this->tcp->segments['B']);

        $this->artisan('tcp:reconcile-worksegments', ['--store' => '03759-00001'])
            ->assertSuccessful();

        $fresh = $rollup->refresh();

        $this->assertSame(180, $fresh->duration_minutes, 'the hours must follow TCP');
        $this->assertSame(ActualShift::REVIEW_WORKED, $fresh->review_state, 'but a verdict is never overwritten');
        $this->assertTrue($fresh->needs_attention, 'it resurfaces instead, because it now covers different hours');

        // Soft, never hard: a vanished segment is a payroll event somebody may
        // need to explain.
        $this->assertSame(1, WorkSegmentRow::query()->onlyTrashed()->count());
    }

    public function test_an_unreviewed_shift_whose_segments_all_vanish_is_removed(): void
    {
        $this->seedSegment('A', '2026-08-06T09:00:00', '2026-08-06T17:00:00');
        $this->sync();

        $this->assertSame(1, ActualShift::count());

        $this->tcp->segments = [];

        $this->artisan('tcp:reconcile-worksegments', ['--store' => '03759-00001'])
            ->assertSuccessful();

        // Nobody ever looked at it and TCP says it did not happen.
        $this->assertSame(0, ActualShift::count());
    }

    // ------------------------------------------------- grouping, by a human

    public function test_a_manager_can_merge_two_shifts_into_one(): void
    {
        // Far enough apart that the gap rule calls them two shifts.
        $this->seedSegment('A', '2026-08-06T09:00:00', '2026-08-06T12:00:00');
        $this->seedSegment('B', '2026-08-06T17:00:00', '2026-08-06T21:00:00');
        $this->sync();

        $this->assertSame(2, ActualShift::count());

        [$first, $second] = ActualShift::query()->orderBy('starts_at_utc')->get()->all();
        $before = count($this->tcp->calls);

        $merged = app(\App\Services\Scheduling\ActualShiftRollup::class)
            ->merge($this->store, $first, [$second->id]);

        $this->assertSame(1, ActualShift::count());
        $this->assertSame(2, $merged->segments()->count());
        // 3h + 4h. The five hours between them are not worked time.
        $this->assertSame(420, $merged->duration_minutes);
        $this->assertTrue($merged->grouping_pinned);
        $this->assertSame($before, count($this->tcp->calls), 'grouping is ours — TCP has nothing to be told');

        // And the next sync must leave the decision alone.
        $this->sync();
        $this->assertSame(1, ActualShift::count());
    }

    public function test_a_manager_can_split_one_shift_into_two(): void
    {
        // Close enough that the gap rule calls them one shift.
        $this->seedSegment('A', '2026-08-06T09:00:00', '2026-08-06T12:00:00');
        $this->seedSegment('B', '2026-08-06T12:30:00', '2026-08-06T17:00:00');
        $this->sync();

        $rollup = ActualShift::sole();
        $moving = WorkSegmentRow::query()->where('tcp_work_segment_id', 'B')->sole();

        $created = app(\App\Services\Scheduling\ActualShiftRollup::class)
            ->split($this->store, $rollup, [$moving->id]);

        $this->assertSame(2, ActualShift::count());
        $this->assertSame(270, $created->duration_minutes);
        $this->assertSame(180, $rollup->refresh()->duration_minutes);
        $this->assertTrue($created->grouping_pinned);
        $this->assertTrue($rollup->refresh()->grouping_pinned);

        $this->sync();
        $this->assertSame(2, ActualShift::count(), 'the gap rule must not glue them back together');
    }

    public function test_a_split_that_would_empty_the_shift_is_refused(): void
    {
        $this->seedSegment('A', '2026-08-06T09:00:00', '2026-08-06T17:00:00');
        $this->sync();

        $rollup = ActualShift::sole();
        $only = WorkSegmentRow::query()->sole();

        $this->expectException(\App\Services\Scheduling\Exceptions\SchedulingException::class);

        app(\App\Services\Scheduling\ActualShiftRollup::class)
            ->split($this->store, $rollup, [$only->id]);
    }

    // -------------------------------------------------------------- helpers

    private function sync(): array
    {
        return app(TcpWorkSegmentSync::class)->sync(
            $this->store,
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-31'),
            true
        );
    }

    private function seedSegment(string $id, string $in, ?string $out): void
    {
        $this->tcp->seedSegment(new TcpWorkSegment(
            id: $id,
            employeeId: '501',
            jobCodeId: 'JOB1',
            timeIn: $in,
            timeOut: $out,
            updatedOn: CarbonImmutable::now()->format('Y-m-d\TH:i:s'),
        ));
    }

    private function plannedAssignment(string $start, string $end): ShiftAssignment
    {
        $shift = Shift::query()->create([
            'store_id' => 1,
            'shift_date' => '2026-08-06',
            'start_time' => $start,
            'end_time' => $end,
            // 09:00 America/Chicago in August is 14:00 UTC.
            'starts_at_utc' => '2026-08-06 14:00:00',
            'ends_at_utc' => '2026-08-06 22:00:00',
            'duration_minutes' => 480,
            'crosses_midnight' => false,
            'shift_type' => 'custom',
        ]);

        return ShiftAssignment::query()->create([
            'shift_id' => $shift->id,
            'employee_id' => 501,
        ]);
    }
}
