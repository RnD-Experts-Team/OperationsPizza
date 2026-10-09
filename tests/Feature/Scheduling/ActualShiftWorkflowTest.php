<?php

namespace Tests\Feature\Scheduling;

use App\Models\ActualShift;
use App\Models\Employee;
use App\Models\EmployeeStore;
use App\Models\HumanityLocation;
use App\Models\HumanityPosition;
use App\Models\HumanityPositionMap;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Store;
use App\Models\TcpWorkSegment as WorkSegmentRow;
use App\Models\TcpJobCode;
use App\Models\User;
use App\Services\Tcp\FakeTcpClient;
use App\Services\Tcp\TcpWorkSegmentSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The manager's review layer: confirming, amending and disputing what actually
 * happened against what was planned.
 *
 * Every case here is a defect that shipped. The through-line is IDENTITY —
 * `upsert()` could only ever key a row by its planned assignment, so ad-hoc
 * coverage (which by definition has none) was recreated on every save.
 */
class ActualShiftWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private FakeTcpClient $tcp;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.auth_server.base_url' => 'http://auth.test',
            'services.auth_server.verify_path' => '/api/v1/auth/token/verify',
            'services.auth_server.service_name' => 'operations-system',
            'services.auth_server.call_token' => 'service-token',
            'humanity.writes_enabled' => true,
            // Worked time is written THROUGH to TCP, which is its system of
            // record — so every case below exercises that path against the fake.
            'tcp.driver' => 'fake',
            'tcp.writes_enabled' => true,
        ]);

        Http::preventStrayRequests();
        Http::fake(['auth.test/*' => Http::response([
            'active' => true,
            'subject_type' => 'user',
            'user' => ['id' => 9, 'name' => 'Dana', 'email' => 'dana@example.com'],
            'roles' => ['Store Manager'],
            'permissions' => [],
            'ext' => ['authorized' => true],
        ])]);

        User::query()->create(['id' => 9, 'name' => 'Dana', 'email' => 'dana@example.com']);

        $store = Store::query()->create([
            'id' => 1,
            'store_number' => '03759-00001',
            'name' => 'Downtown',
            'timezone' => 'America/Chicago',
        ]);
        $store->settings();

        HumanityLocation::query()->create(['store_id' => 1, 'humanity_location_id' => 'LOC1', 'name' => 'Downtown']);
        HumanityPosition::query()->create(['humanity_position_id' => 'POS1', 'humanity_location_id' => 'LOC1', 'name' => 'Kitchen']);
        HumanityPositionMap::query()->create(['store_id' => 1, 'position_label' => null, 'humanity_position_id' => 'POS1', 'is_default' => true]);

        // The TCP job-code catalog, as tcp:sync-catalog mirrors it.
        TcpJobCode::query()->create([
            'tcp_job_code_id' => 'JOB1',
            'description' => 'Kitchen - 3759-01',
            'store_number' => '03759-00001',
            'clockable' => true,
            'is_active' => true,
        ]);

        Employee::query()->create([
            'id' => 501,
            'first_name' => 'Marco',
            'last_name' => 'Rossi',
            'active' => true,
            'current_status' => 'hired',
            'humanity_employee_id' => '88213',
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

    /** Segments TCP is holding right now, by id. */
    private function tcpSegments(): array
    {
        return $this->tcp->segments;
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer 1|test-token', 'Accept' => 'application/json'];
    }

    private function plannedAssignment(string $start = '10:00:00', string $end = '16:00:00', ?string $label = 'Kitchen'): ShiftAssignment
    {
        $shift = Shift::query()->create([
            'store_id' => 1,
            'humanity_location_id' => 'LOC1',
            'humanity_position_id' => 'POS1',
            'humanity_shift_id' => 'H' . fake()->unique()->numberBetween(1, 99999),
            'shift_date' => '2026-08-05',
            'start_time' => $start,
            'end_time' => $end,
            'starts_at_utc' => '2026-08-05 15:00:00',
            'ends_at_utc' => '2026-08-05 21:00:00',
            'duration_minutes' => 360,
            'crosses_midnight' => false,
            'label' => $label,
        ]);

        return $shift->assignments()->create([
            'employee_id' => 501,
            'humanity_employee_id' => '88213',
            'status' => 'assigned',
        ]);
    }

    private function adHocCoverage(): string
    {
        return $this->postJson('/api/v1/stores/03759-00001/actual-shifts', [
            'employee_id' => 501,
            'shift_date' => '2026-08-05',
            'start_time' => '10:00',
            'end_time' => '16:00',
            'label' => 'Cover',
        ], $this->headers())->assertCreated()->json('data.id');
    }

    // ------------------------------------------------------------- identity

    public function test_editing_ad_hoc_coverage_amends_it_instead_of_creating_another(): void
    {
        $id = $this->adHocCoverage();
        $this->assertSame(1, ActualShift::query()->count());

        $this->postJson("/api/v1/stores/03759-00001/actual-shifts/{$id}", [
            'start_time' => '11:00',
            'end_time' => '17:00',
        ], $this->headers())->assertOk();

        $this->assertSame(1, ActualShift::query()->count(), 'the edit must not leave a second copy behind');
        $this->assertSame('11:00:00', ActualShift::query()->first()->start_time);
    }

    public function test_editing_ad_hoc_coverage_repeatedly_never_stacks_rows(): void
    {
        $id = $this->adHocCoverage();

        foreach (['12:00', '13:00', '14:00'] as $start) {
            $this->postJson("/api/v1/stores/03759-00001/actual-shifts/{$id}", [
                'start_time' => $start,
                'end_time' => '18:00',
            ], $this->headers())->assertOk();
        }

        $this->assertSame(1, ActualShift::query()->count());
        $this->assertSame('14:00:00', ActualShift::query()->first()->start_time);
    }

    public function test_posting_identical_coverage_twice_is_a_double_submit_not_two_shifts(): void
    {
        $first = $this->adHocCoverage();

        $second = $this->postJson('/api/v1/stores/03759-00001/actual-shifts', [
            'employee_id' => 501,
            'shift_date' => '2026-08-05',
            'start_time' => '10:00',
            'end_time' => '16:00',
            'label' => 'Cover',
        ], $this->headers())->assertCreated()->json('data.id');

        $this->assertSame($first, $second, 'the retry should land on the same entry');
        $this->assertSame(1, ActualShift::query()->count());
    }

    public function test_coverage_at_different_times_is_still_a_separate_entry(): void
    {
        $this->adHocCoverage();

        $this->postJson('/api/v1/stores/03759-00001/actual-shifts', [
            'employee_id' => 501,
            'shift_date' => '2026-08-05',
            'start_time' => '18:00',
            'end_time' => '22:00',
        ], $this->headers())->assertCreated();

        $this->assertSame(2, ActualShift::query()->count());
    }

    public function test_reviewing_a_planned_shift_twice_amends_the_same_entry(): void
    {
        $assignment = $this->plannedAssignment();

        $this->postJson("/api/v1/stores/03759-00001/shift-assignments/{$assignment->id}/confirm-actual", [], $this->headers())
            ->assertCreated();
        $this->postJson("/api/v1/stores/03759-00001/shift-assignments/{$assignment->id}/confirm-actual", [], $this->headers())
            ->assertCreated();

        $this->assertSame(1, ActualShift::query()->count());
    }

    // --------------------------------------------------------------- absence

    public function test_editing_an_absence_does_not_silently_record_them_as_having_worked(): void
    {
        $assignment = $this->plannedAssignment();

        $id = $this->postJson("/api/v1/stores/03759-00001/shift-assignments/{$assignment->id}/confirm-actual", [], $this->headers())
            ->assertCreated()->json('data.id');

        $this->postJson("/api/v1/stores/03759-00001/actual-shifts/{$id}/absent", ['note' => 'no show'], $this->headers())
            ->assertOk();

        // A manager corrects the note on the absence.
        $this->postJson("/api/v1/stores/03759-00001/actual-shifts/{$id}", [
            'note' => 'no show, called in later',
        ], $this->headers())->assertOk();

        $this->assertSame(ActualShift::REVIEW_ABSENT, ActualShift::query()->find($id)->review_state);
        $this->assertSame('no show, called in later', ActualShift::query()->find($id)->manager_note);
    }

    public function test_an_absence_survives_a_time_edit_too(): void
    {
        $assignment = $this->plannedAssignment();
        $id = $this->postJson("/api/v1/stores/03759-00001/shift-assignments/{$assignment->id}/confirm-actual", [], $this->headers())
            ->json('data.id');

        $this->postJson("/api/v1/stores/03759-00001/actual-shifts/{$id}/absent", [], $this->headers())->assertOk();

        $this->postJson("/api/v1/stores/03759-00001/actual-shifts/{$id}", [
            'start_time' => '11:00',
            'end_time' => '15:00',
        ], $this->headers())->assertOk();

        $this->assertSame(ActualShift::REVIEW_ABSENT, ActualShift::query()->find($id)->review_state);
    }

    public function test_confirming_as_planned_is_an_explicit_way_back_out_of_an_absence(): void
    {
        $assignment = $this->plannedAssignment();
        $id = $this->postJson("/api/v1/stores/03759-00001/shift-assignments/{$assignment->id}/confirm-actual", [], $this->headers())
            ->json('data.id');

        $this->postJson("/api/v1/stores/03759-00001/actual-shifts/{$id}/absent", [], $this->headers())->assertOk();

        // The manager was wrong — they did turn up. An explicit status wins.
        $this->postJson("/api/v1/stores/03759-00001/shift-assignments/{$assignment->id}/confirm-actual", [], $this->headers())
            ->assertCreated();

        $this->assertSame(ActualShift::REVIEW_WORKED, ActualShift::query()->find($id)->review_state);
    }

    // ------------------------------------------------------- derived status

    public function test_a_renamed_shift_is_modified_not_confirmed(): void
    {
        $assignment = $this->plannedAssignment(label: 'Kitchen');

        $response = $this->postJson('/api/v1/stores/03759-00001/actual-shifts', [
            'employee_id' => 501,
            'shift_assignment_id' => $assignment->id,
            'shift_date' => '2026-08-05',
            // Identical times, different label.
            'start_time' => '10:00',
            'end_time' => '16:00',
            'label' => 'Covered the fryer instead',
        ], $this->headers())->assertCreated();

        $this->assertSame(ActualShift::VARIANCE_DIFFERS, $response->json('data.time_variance'));
        $this->assertNull($response->json('data.status'), 'the pre-split string is no longer emitted');
    }

    public function test_an_identical_entry_is_confirmed(): void
    {
        $assignment = $this->plannedAssignment(label: 'Kitchen');

        $response = $this->postJson('/api/v1/stores/03759-00001/actual-shifts', [
            'employee_id' => 501,
            'shift_assignment_id' => $assignment->id,
            'shift_date' => '2026-08-05',
            'start_time' => '10:00',
            'end_time' => '16:00',
            'label' => 'Kitchen',
        ], $this->headers())->assertCreated();

        $this->assertSame(ActualShift::VARIANCE_MATCHES, $response->json('data.time_variance'));
    }

    // ---------------------------------------------------------------- source

    public function test_amending_a_timeclock_entry_does_not_relabel_it_as_hand_entered(): void
    {
        $this->tcp->seedSegment(new \App\Services\Tcp\Dto\TcpWorkSegment(
            id: 'WS-1',
            employeeId: '501',
            jobCodeId: 'JOB1',
            timeIn: '2026-08-05T10:03:00',
            timeOut: '2026-08-05T16:07:00',
        ));

        $actual = $this->seedPunchedShift('WS-1', [
            'start_time' => '10:03:00',
            'end_time' => '16:07:00',
            'starts_at_utc' => '2026-08-05 15:03:00',
            'ends_at_utc' => '2026-08-05 21:07:00',
            'duration_minutes' => 364,
        ]);

        $this->postJson("/api/v1/stores/03759-00001/actual-shifts/{$actual->id}", [
            'start_time' => '10:00',
            'end_time' => '16:00',
        ], $this->headers())->assertOk();

        $fresh = $actual->refresh();
        $this->assertSame('timeclock', $fresh->source, 'a corrected punch is still a punch');
        $this->assertSame('WS-1', $this->segmentIdOf($fresh->id), 'the TCP identity must survive the edit');
    }

    // ------------------------------------------------------------ edit scope

    public function test_an_entry_logged_against_the_wrong_day_can_be_moved(): void
    {
        $id = $this->adHocCoverage();

        $this->postJson("/api/v1/stores/03759-00001/actual-shifts/{$id}", [
            'shift_date' => '2026-08-06',
        ], $this->headers())->assertOk();

        $this->assertSame('2026-08-06', ActualShift::query()->find($id)->shift_date->toDateString());
        $this->assertSame(1, ActualShift::query()->count());
    }

    public function test_a_label_can_be_cleared_once_set(): void
    {
        $id = $this->adHocCoverage();

        $this->postJson("/api/v1/stores/03759-00001/actual-shifts/{$id}", [
            'label' => null,
        ], $this->headers())->assertOk();

        $this->assertNull(ActualShift::query()->find($id)->label);
    }

    // ------------------------------------------------------- TCP write-through

    public function test_recording_coverage_writes_the_hours_to_tcp(): void
    {
        $id = $this->adHocCoverage();

        $segments = $this->tcpSegments();
        $this->assertCount(1, $segments, 'the hours must exist in TCP, not only here');

        $segment = reset($segments);
        $this->assertSame('501', $segment->employeeId);
        $this->assertSame('2026-08-05T10:00:00', $segment->timeIn);
        $this->assertSame('2026-08-05T16:00:00', $segment->timeOut);

        // And the local row is bound to it, which is what stops the hourly
        // sync from treating this as an unknown segment and duplicating it.
        $this->assertSame($segment->id, $this->segmentIdOf($id));
    }

    public function test_amending_an_entry_amends_the_same_tcp_segment(): void
    {
        $id = $this->adHocCoverage();
        $segmentId = $this->segmentIdOf($id);

        $this->postJson("/api/v1/stores/03759-00001/actual-shifts/{$id}", [
            'start_time' => '11:00',
            'end_time' => '17:00',
        ], $this->headers())->assertOk();

        $segments = $this->tcpSegments();
        $this->assertCount(1, $segments, 'an edit must not leave a second segment in TCP either');
        $this->assertSame('2026-08-05T11:00:00', $segments[$segmentId]->timeIn);
        $this->assertSame('2026-08-05T17:00:00', $segments[$segmentId]->timeOut);
    }

    public function test_marking_a_no_show_removes_the_hours_from_tcp(): void
    {
        $id = $this->adHocCoverage();
        $this->assertCount(1, $this->tcpSegments());

        $this->postJson("/api/v1/stores/03759-00001/actual-shifts/{$id}/absent", [
            'note' => 'never turned up',
        ], $this->headers())->assertOk();

        $this->assertCount(0, $this->tcpSegments(), 'a no-show must not stay on the payroll clock');
        $this->assertNull($this->segmentIdOf($id), 'an absence keeps no segment');
        $this->assertSame(ActualShift::REVIEW_ABSENT, ActualShift::query()->find($id)->review_state);
    }

    public function test_deleting_an_entry_removes_its_tcp_segment(): void
    {
        $id = $this->adHocCoverage();

        $this->deleteJson("/api/v1/stores/03759-00001/actual-shifts/{$id}", [], $this->headers())
            ->assertNoContent();

        $this->assertCount(0, $this->tcpSegments());
    }

    public function test_nothing_is_recorded_locally_when_tcp_refuses_the_write(): void
    {
        $this->tcp->failNext('createWorkSegment');

        $this->postJson('/api/v1/stores/03759-00001/actual-shifts', [
            'employee_id' => 501,
            'shift_date' => '2026-08-05',
            'start_time' => '10:00',
            'end_time' => '16:00',
        ], $this->headers())->assertStatus(502);

        // No orphan: a row TCP never accepted would be hours that exist on the
        // dashboard and nowhere payroll can see.
        $this->assertSame(0, ActualShift::query()->count());
    }

    public function test_an_employee_missing_from_tcp_cannot_have_hours_recorded(): void
    {
        Employee::query()->where('id', 501)->update(['tcp_employee_id' => null]);

        $this->postJson('/api/v1/stores/03759-00001/actual-shifts', [
            'employee_id' => 501,
            'shift_date' => '2026-08-05',
            'start_time' => '10:00',
            'end_time' => '16:00',
        ], $this->headers())->assertStatus(409)
            ->assertJsonPath('error.code', 'EMPLOYEE_NOT_IN_TCP');

        $this->assertSame(0, ActualShift::query()->count());
    }

    public function test_an_edit_survives_the_segment_being_deleted_in_tcps_own_ui(): void
    {
        $id = $this->adHocCoverage();

        // A supervisor removes it directly in TCP; our id is now stale.
        $this->tcp->segments = [];

        $this->postJson("/api/v1/stores/03759-00001/actual-shifts/{$id}", [
            'start_time' => '11:00',
            'end_time' => '17:00',
        ], $this->headers())->assertOk();

        $segments = $this->tcpSegments();
        $this->assertCount(1, $segments, 'the correction should be re-created, not rejected');
        $this->assertSame($id, (string) ActualShift::query()->find($id)->id);
        $this->assertSame(1, ActualShift::query()->count());
    }

    public function test_linking_a_clock_in_to_its_plan_leaves_the_punch_untouched_in_tcp(): void
    {
        // A punch TCP recorded, imported as unlinked ad-hoc coverage.
        $this->tcp->seedSegment(new \App\Services\Tcp\Dto\TcpWorkSegment(
            id: 'WS-9',
            employeeId: '501',
            jobCodeId: 'JOB1',
            timeIn: '2026-08-05T09:58:00',
            timeOut: '2026-08-05T16:04:00',
            actualTimeIn: '2026-08-05T09:58:00',
            actualTimeOut: '2026-08-05T16:04:00',
        ));

        $punch = $this->seedPunchedShift('WS-9');

        $assignment = $this->plannedAssignment();

        $this->postJson("/api/v1/stores/03759-00001/actual-shifts/{$punch->id}", [
            'shift_assignment_id' => $assignment->id,
        ], $this->headers())->assertOk();

        $fresh = $punch->refresh();
        $this->assertSame($assignment->id, $fresh->shift_assignment_id, 'the punch is now attributed to the plan');
        $this->assertSame('WS-9', $this->segmentIdOf($fresh->id));
        $this->assertSame(1, ActualShift::query()->count(), 'linking must not create a second entry');

        // The evidence in TCP is untouched: same segment, same real punch times.
        $segments = $this->tcpSegments();
        $this->assertCount(1, $segments);
        $this->assertSame('2026-08-05T09:58:00', $segments['WS-9']->actualTimeIn);
        $this->assertSame('2026-08-05T16:04:00', $segments['WS-9']->actualTimeOut);
    }

    public function test_a_change_that_tcp_does_not_model_spends_no_quota(): void
    {
        $id = $this->adHocCoverage();
        $before = count($this->tcp->calls);

        // Label is ours alone — TCP has nowhere to put it.
        $this->postJson("/api/v1/stores/03759-00001/actual-shifts/{$id}", [
            'label' => 'Renamed',
        ], $this->headers())->assertOk();

        $this->assertSame($before, count($this->tcp->calls), 'no TCP call for a local-only change');
        $this->assertSame('Renamed', ActualShift::query()->find($id)->label);
    }

    public function test_marking_a_planned_shift_absent_takes_one_call_and_never_says_they_worked(): void
    {
        $assignment = $this->plannedAssignment();
        $before = count($this->tcp->calls);

        $this->postJson("/api/v1/stores/03759-00001/shift-assignments/{$assignment->id}/absent-actual", [
            'note' => 'no call, no show',
        ], $this->headers())->assertCreated()
            ->assertJsonPath('data.review_state', ActualShift::REVIEW_ABSENT);

        $actual = ActualShift::query()->first();
        $this->assertSame(ActualShift::REVIEW_ABSENT, $actual->review_state);
        $this->assertSame('no call, no show', $actual->manager_note);
        $this->assertNull($this->segmentIdOf($actual->id));

        // The old two-step created a segment purely to delete it. A no-show
        // should never touch TCP at all.
        $this->assertSame($before, count($this->tcp->calls), 'a no-show costs no TCP call');
        $this->assertCount(0, $this->tcpSegments());
    }

    // ------------------------------------- derived vs asserted, kept apart

    /**
     * The whole point of splitting the column.
     *
     * The hourly sync may recompute the COMPARISON as often as it likes — it is
     * a function of the data. It must never touch the VERDICT, which is a
     * person's judgement. Before the split both lived in `status`, so a sync an
     * hour later could quietly overturn what a manager had decided.
     */
    public function test_the_sync_recomputes_the_comparison_but_never_the_verdict(): void
    {
        $assignment = $this->plannedAssignment(label: 'Kitchen');

        // A manager reviews the shift and renames it: same times, new label.
        $id = $this->postJson('/api/v1/stores/03759-00001/actual-shifts', [
            'employee_id' => 501,
            'shift_assignment_id' => $assignment->id,
            'shift_date' => '2026-08-05',
            'start_time' => '10:00',
            'end_time' => '16:00',
            'label' => 'Covered the fryer instead',
        ], $this->headers())->assertCreated()->json('data.id');

        $actual = ActualShift::query()->find($id);
        $this->assertSame(ActualShift::VARIANCE_DIFFERS, $actual->time_variance);
        $this->assertSame(ActualShift::REVIEW_WORKED, $actual->review_state);

        // Now the hourly TCP sync reads that same segment back.
        app(TcpWorkSegmentSync::class)->syncOne(
            Store::query()->find(1),
            Employee::query()->find(501),
            $this->tcp->segments[$this->segmentIdOf($actual->id)],
        );

        $fresh = $actual->refresh();

        /*
         | Still `differs`, and that is the fix.
         |
         | The sync and the review path used to derive this differently — the
         | sync compared times only, the review path compared times AND label —
         | so this same shift read `matches` after a sync and `differs` after any
         | manager edit, flipping back and forth on a schedule. They now share
         | one method, and the label a manager gave it is part of the comparison.
         */
        $this->assertSame(ActualShift::VARIANCE_DIFFERS, $fresh->time_variance);
        // Untouched. This is the assertion that used to fail.
        $this->assertSame(ActualShift::REVIEW_WORKED, $fresh->review_state);
    }

    public function test_amending_an_unreviewed_punch_is_itself_an_act_of_reviewing_it(): void
    {
        $this->tcp->seedSegment(new \App\Services\Tcp\Dto\TcpWorkSegment(
            id: 'WS-7',
            employeeId: '501',
            jobCodeId: 'JOB1',
            timeIn: '2026-08-05T09:58:00',
            timeOut: '2026-08-05T16:04:00',
        ));

        $punch = $this->seedPunchedShift('WS-7');

        $this->postJson("/api/v1/stores/03759-00001/actual-shifts/{$punch->id}", [
            'start_time' => '10:00',
            'end_time' => '16:00',
        ], $this->headers())->assertOk();

        $fresh = $punch->refresh();
        $this->assertSame(ActualShift::REVIEW_WORKED, $fresh->review_state, 'a manager touched it, so it is reviewed');
        $this->assertSame('timeclock', $fresh->source, 'but it is still a punch, not a hand-entered shift');
    }

    public function test_an_absence_can_be_reversed_explicitly(): void
    {
        $id = $this->adHocCoverage();

        $this->postJson("/api/v1/stores/03759-00001/actual-shifts/{$id}/absent", [], $this->headers())->assertOk();
        $this->assertSame(ActualShift::REVIEW_ABSENT, ActualShift::query()->find($id)->review_state);
        $this->assertCount(0, $this->tcpSegments());

        // They did turn up after all. An explicit verdict is the way back.
        $this->postJson("/api/v1/stores/03759-00001/actual-shifts/{$id}", [
            'review_state' => 'worked',
        ], $this->headers())->assertOk();

        $this->assertSame(ActualShift::REVIEW_WORKED, ActualShift::query()->find($id)->review_state);
        $this->assertCount(1, $this->tcpSegments(), 'and the hours go back to TCP');
    }

    /**
     * `source` is derived from the segments, and could not be derived from
     * merely HAVING segments — a manager's hand-entry is pushed to TCP and gets
     * one too. Only the segment's own `origin` distinguishes them.
     */
    public function test_source_is_derived_from_where_the_punch_was_made(): void
    {
        $handEntered = $this->postJson('/api/v1/stores/03759-00001/actual-shifts', [
            'employee_id' => 501,
            'shift_date' => '2026-08-05',
            'start_time' => '10:00',
            'end_time' => '16:00',
        ], $this->headers())->assertCreated()->json('data.id');

        $this->assertSame('manual', ActualShift::query()->find($handEntered)->source);
        $this->assertSame(
            WorkSegmentRow::ORIGIN_MANUAL,
            WorkSegmentRow::query()->where('actual_shift_id', $handEntered)->value('origin'),
            'a hand-entry still creates a TCP segment, so HAVING one proves nothing'
        );

        $punched = $this->seedPunchedShift('WS-SRC');

        $this->assertSame('timeclock', $punched->refresh()->source);
    }

    /**
     * A shift already recorded from a punch: the roll-up, plus the segment
     * behind it. Hand-building only the roll-up would model something that can
     * no longer exist — worked hours with no punch under them.
     */
    private function seedPunchedShift(string $segmentId, array $shift = []): ActualShift
    {
        $actual = ActualShift::query()->create(array_merge([
            'store_id' => 1,
            'employee_id' => 501,
            'shift_date' => '2026-08-05',
            'start_time' => '09:58:00',
            'end_time' => '16:04:00',
            'starts_at_utc' => '2026-08-05 14:58:00',
            'ends_at_utc' => '2026-08-05 21:04:00',
            'duration_minutes' => 366,
            'crosses_midnight' => false,
            'time_variance' => ActualShift::VARIANCE_UNPLANNED,
            'review_state' => ActualShift::REVIEW_UNREVIEWED,
        ], $shift));

        WorkSegmentRow::query()->create([
            'tcp_work_segment_id' => $segmentId,
            'actual_shift_id' => $actual->id,
            'employee_id' => $actual->employee_id,
            'store_id' => $actual->store_id,
            'tcp_employee_id' => '501',
            'tcp_job_code_id' => 'JOB1',
            // Found in TCP rather than punched here — the case this whole sync
            // exists for.
            'origin' => WorkSegmentRow::ORIGIN_DISCOVERED,
            'time_in' => $actual->shift_date->toDateString() . ' ' . $actual->start_time,
            'time_out' => $actual->shift_date->toDateString() . ' ' . $actual->end_time,
            'starts_at_utc' => $actual->starts_at_utc,
            'ends_at_utc' => $actual->ends_at_utc,
            'duration_minutes' => $actual->duration_minutes,
        ]);

        return $actual;
    }

    private function segmentIdOf(int|string $actualId): ?string
    {
        return WorkSegmentRow::query()
            ->where('actual_shift_id', $actualId)
            ->value('tcp_work_segment_id');
    }

    public function test_a_store_outside_the_rollout_allowlist_cannot_write_hours(): void
    {
        config(['external.allowed_stores' => '09999-00001']);

        $this->postJson('/api/v1/stores/03759-00001/actual-shifts', [
            'employee_id' => 501,
            'shift_date' => '2026-08-05',
            'start_time' => '10:00',
            'end_time' => '16:00',
        ], $this->headers())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'STORE_NOT_ALLOWLISTED');

        $this->assertSame(0, ActualShift::query()->count());
        $this->assertCount(0, $this->tcpSegments(), 'nothing may reach TCP for a store off the allowlist');
    }
}
