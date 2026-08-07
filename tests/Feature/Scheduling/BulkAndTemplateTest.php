<?php

namespace Tests\Feature\Scheduling;

use App\Jobs\ProcessBulkOperationJob;
use App\Models\ActualShift;
use App\Models\Employee;
use App\Models\EmployeeStore;
use App\Models\HumanityLocation;
use App\Models\HumanityPosition;
use App\Models\HumanityPositionMap;
use App\Models\ScheduleBulkOperation;
use App\Models\Shift;
use App\Models\Store;
use App\Services\Humanity\FakeHumanityClient;
use App\Services\Scheduling\ActualShiftService;
use App\Services\Scheduling\BulkOperationService;
use App\Services\Scheduling\PublishedScheduleService;
use App\Services\Scheduling\ScheduleTemplateService;
use App\Services\Scheduling\ShiftWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BulkAndTemplateTest extends TestCase
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

        foreach ([501 => 'Marco', 502 => 'Sofia'] as $id => $firstName) {
            Employee::query()->create([
                'id' => $id,
                'first_name' => $firstName,
                'last_name' => 'Test',
                'active' => true,
                'humanity_employee_id' => (string) (88000 + $id),
            ]);

            EmployeeStore::query()->create([
                'employee_id' => $id,
                'store_number' => '03759-00001',
                'store_id' => 1,
                'status' => 'hired',
                'active' => true,
            ]);
        }

        $this->humanity = app(FakeHumanityClient::class);
        $this->humanity->seedLocation('LOC1');
        $this->humanity->seedPosition('POS1', 'Kitchen', 'LOC1');
        $this->humanity->seedEmployee('88501');
        $this->humanity->seedEmployee('88502');

        config(['humanity.writes_enabled' => true, 'humanity.requests_per_second' => 0]);
    }

    private function seedWeek(string $weekStart = '2026-08-04'): void
    {
        $writer = app(ShiftWriteService::class);

        $writer->create($this->store, [
            'employee_id' => 501,
            'shift_date' => $weekStart,
            'start_time' => '09:00',
            'end_time' => '17:00',
            'label' => 'Morning',
            'shift_type' => 'morning',
        ]);

        $writer->create($this->store, [
            'employee_id' => 502,
            'shift_date' => date('Y-m-d', strtotime($weekStart . ' +2 days')),
            'start_time' => '16:00',
            'end_time' => '22:00',
            'label' => 'Evening',
            'shift_type' => 'evening',
        ]);
    }

    private function runBulk(ScheduleBulkOperation $operation): ScheduleBulkOperation
    {
        app(ProcessBulkOperationJob::class, ['operationId' => $operation->id])
            ->handle(app(ShiftWriteService::class));

        return $operation->fresh();
    }

    public function test_copy_week_recreates_every_shift_on_the_target_week(): void
    {
        $this->seedWeek('2026-08-04');

        $operation = app(BulkOperationService::class)
            ->copyWeek($this->store, '2026-08-04', '2026-08-11', 'replace', null);

        $this->assertSame(ScheduleBulkOperation::STATUS_QUEUED, $operation->status);
        $this->assertSame(2, $operation->total_items);

        $operation = $this->runBulk($operation);

        $this->assertSame(ScheduleBulkOperation::STATUS_COMPLETED, $operation->status);
        $this->assertSame(2, $operation->succeeded_items);

        // Same day offsets, one week later.
        $copied = Shift::query()->whereBetween('shift_date', ['2026-08-11', '2026-08-17'])->get();
        $this->assertCount(2, $copied);
        $this->assertContains('2026-08-11', $copied->pluck('shift_date')->map->toDateString()->all());
        $this->assertContains('2026-08-13', $copied->pluck('shift_date')->map->toDateString()->all());
    }

    public function test_replace_mode_sequences_deletes_before_creates(): void
    {
        $this->seedWeek('2026-08-04');
        $this->seedWeek('2026-08-11'); // target already has shifts

        $operation = app(BulkOperationService::class)
            ->copyWeek($this->store, '2026-08-04', '2026-08-11', 'replace', null);

        // Deletes are their own items and go first, so a mid-run failure is
        // visible rather than silently doubling the week.
        $items = $operation->items()->orderBy('sequence')->get();
        $this->assertSame('delete', $items->first()->action);
        $this->assertSame(2, $items->where('action', 'delete')->count());
        $this->assertSame(2, $items->where('action', 'create')->count());

        $operation = $this->runBulk($operation);

        $this->assertSame(ScheduleBulkOperation::STATUS_COMPLETED, $operation->status);
        $this->assertSame(2, Shift::query()->whereBetween('shift_date', ['2026-08-11', '2026-08-17'])->count());
    }

    public function test_a_partial_failure_completes_with_errors_and_keeps_the_rest(): void
    {
        $this->seedWeek('2026-08-04');

        $operation = app(BulkOperationService::class)
            ->copyWeek($this->store, '2026-08-04', '2026-08-11', 'merge', null);

        $this->humanity->failNext('createShift');

        $operation = $this->runBulk($operation);

        // No rollback by design: a partial week beats deleting shifts people
        // may already have seen.
        $this->assertSame(ScheduleBulkOperation::STATUS_COMPLETED_WITH_ERRORS, $operation->status);
        $this->assertSame(1, $operation->succeeded_items);
        $this->assertSame(1, $operation->failed_items);

        $presented = app(BulkOperationService::class)->present($operation);
        $this->assertCount(1, $presented['items']);
        $this->assertNotNull($presented['items'][0]['error_message']);
        // The failed item names the person and slot so a manager can fix it.
        $this->assertNotNull($presented['items'][0]['employee_name']);
    }

    public function test_retry_failed_reruns_only_the_failures(): void
    {
        $this->seedWeek('2026-08-04');

        $operation = app(BulkOperationService::class)
            ->copyWeek($this->store, '2026-08-04', '2026-08-11', 'merge', null);

        $this->humanity->failNext('createShift');
        $operation = $this->runBulk($operation);
        $this->assertSame(1, $operation->failed_items);

        $operation->items()->where('status', 'failed')->update(['status' => 'pending']);
        $operation->update(['failed_items' => 0, 'status' => ScheduleBulkOperation::STATUS_QUEUED]);

        $operation = $this->runBulk($operation);

        $this->assertSame(ScheduleBulkOperation::STATUS_COMPLETED, $operation->status);
        $this->assertSame(2, Shift::query()->whereBetween('shift_date', ['2026-08-11', '2026-08-17'])->count());
    }

    public function test_clear_week_deletes_everything_in_the_week(): void
    {
        $this->seedWeek('2026-08-04');

        $operation = $this->runBulk(
            app(BulkOperationService::class)->clearWeek($this->store, '2026-08-04', null)
        );

        $this->assertSame(ScheduleBulkOperation::STATUS_COMPLETED, $operation->status);
        $this->assertSame(0, Shift::query()->count());
        $this->assertCount(0, $this->humanity->shifts);
    }

    public function test_a_template_captures_a_week_by_day_index_and_reapplies_it(): void
    {
        $this->seedWeek('2026-08-04');

        $template = app(ScheduleTemplateService::class)
            ->createFromWeek($this->store, 'Standard Week', 'The usual', '2026-08-04', null);

        $this->assertSame(2, $template->shift_count);
        // Week-relative, which is what makes it reusable on any week.
        $this->assertSame([0, 2], $template->shifts->pluck('day_index')->sort()->values()->all());

        $operation = $this->runBulk(
            app(BulkOperationService::class)->applyTemplate($this->store, $template, '2026-08-18', 'merge', null)
        );

        $this->assertSame(ScheduleBulkOperation::STATUS_COMPLETED, $operation->status);

        $applied = Shift::query()->whereBetween('shift_date', ['2026-08-18', '2026-08-24'])->get();
        $this->assertCount(2, $applied);
        $this->assertContains('2026-08-18', $applied->pluck('shift_date')->map->toDateString()->all());
        $this->assertContains('2026-08-20', $applied->pluck('shift_date')->map->toDateString()->all());
    }

    public function test_confirming_a_planned_shift_creates_a_matching_actual(): void
    {
        $this->seedWeek('2026-08-04');

        $assignment = Shift::query()->first()->assignments()->first();

        $actual = app(ActualShiftService::class)->confirmPlanned($this->store, $assignment);

        $this->assertSame(ActualShift::STATUS_CONFIRMED, $actual->status);
        $this->assertSame($assignment->id, $actual->shift_assignment_id);
        $this->assertSame(480, $actual->duration_minutes);
    }

    public function test_an_actual_with_different_times_is_derived_as_modified(): void
    {
        $this->seedWeek('2026-08-04');

        $assignment = Shift::query()->first()->assignments()->first();

        $actual = app(ActualShiftService::class)->upsert($this->store, [
            'employee_id' => 501,
            'shift_assignment_id' => $assignment->id,
            'shift_date' => '2026-08-04',
            'start_time' => '10:00',
            'end_time' => '17:00',
            'note' => 'Clocked in an hour late',
        ]);

        // Status is derived, never taken from the client, so it can't drift
        // from the times it describes.
        $this->assertSame(ActualShift::STATUS_MODIFIED, $actual->status);
        $this->assertSame(420, $actual->duration_minutes);
    }

    public function test_ad_hoc_coverage_with_no_planned_shift_is_added(): void
    {
        $actual = app(ActualShiftService::class)->upsert($this->store, [
            'employee_id' => 502,
            'shift_date' => '2026-08-05',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'note' => 'Covered for Marco',
        ]);

        $this->assertSame(ActualShift::STATUS_ADDED, $actual->status);
        $this->assertNull($actual->shift_assignment_id);
    }

    public function test_reviewing_the_same_assignment_twice_amends_rather_than_duplicates(): void
    {
        $this->seedWeek('2026-08-04');
        $assignment = Shift::query()->first()->assignments()->first();

        app(ActualShiftService::class)->confirmPlanned($this->store, $assignment);
        app(ActualShiftService::class)->markAbsent($this->store, $assignment, 'No call, no show');

        $this->assertSame(1, ActualShift::query()->count());
        $this->assertSame(ActualShift::STATUS_ABSENT, ActualShift::sole()->status);
    }

    public function test_a_shift_on_the_last_day_of_the_week_is_included(): void
    {
        // Regression: `date`-cast columns are written as "Y-m-d H:i:s", so a
        // plain `<= '2026-08-10'` comparison excluded the final day and the
        // Monday column silently came back empty.
        app(ShiftWriteService::class)->create($this->store, [
            'employee_id' => 501,
            'shift_date' => '2026-08-10', // day_index 6 of the Tue-start week
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        $assignments = app(\App\Services\Scheduling\ShiftQueryService::class)
            ->assignmentsForRange($this->store, '2026-08-04', '2026-08-10');

        $this->assertCount(1, $assignments);
    }

    public function test_publishing_freezes_the_week_and_supersedes_the_previous_record(): void
    {
        $this->seedWeek('2026-08-04');

        $service = app(PublishedScheduleService::class);

        $first = $service->publish($this->store, '2026-08-04');
        $this->assertSame(2, $first->shift_count);
        $this->assertCount(2, $first->shift_snapshot);

        $second = $service->publish($this->store, '2026-08-04');

        // "The published week" must never be ambiguous.
        $this->assertNotNull($first->fresh()->unpublished_at);
        $this->assertNull($second->unpublished_at);
    }
}
