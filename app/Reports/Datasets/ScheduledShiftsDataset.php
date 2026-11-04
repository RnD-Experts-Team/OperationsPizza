<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Contract\Column;
use App\Reports\Contract\ReportDataset;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The plan: one row per employee assigned to a shift (`shift_assignments`
 * with status `assigned`, as the schedule grid counts them). `slots` counts
 * those assignments and `scheduled_minutes` sums the shift's length once per
 * assigned employee. The position is the Humanity position, which the grid
 * shows as the department.
 */
class ScheduledShiftsDataset extends ReportDataset
{
    public const KEY = 'operations.scheduled_shifts';

    public function label(): string
    {
        return 'Scheduled shifts';
    }

    public function grain(): array
    {
        return ['store', 'date', 'employee'];
    }

    public function columns(): array
    {
        return [
            new Column('store', 'Store', 'string', 'stores.store_number', groupable: true),
            new Column('date', 'Shift date', 'date', 'shifts.shift_date', groupable: true),
            new Column('employee', 'Employee', 'integer', 'shift_assignments.employee_id', groupable: true, aggregations: ['count_distinct']),
            new Column('position', 'Position', 'string', 'humanity_positions.name', groupable: true),
            new Column('shift_type', 'Shift type', 'enum', 'shifts.shift_type', options: [
                ['value' => 'morning', 'label' => 'Morning'],
                ['value' => 'evening', 'label' => 'Evening'],
                ['value' => 'night', 'label' => 'Night'],
                ['value' => 'split', 'label' => 'Split'],
                ['value' => 'custom', 'label' => 'Custom'],
            ], groupable: true),
            new Column('is_published', 'Published', 'boolean', 'shifts.is_published', groupable: true),
            new Column('scheduled_minutes', 'Scheduled minutes', 'integer', 'shifts.duration_minutes', filterable: false, measure: true, aggregations: ['sum']),
            new Column('slots', 'Scheduled slots', 'integer', 'shift_assignments.id', filterable: false, measure: true, aggregations: ['count']),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('shift_assignments')
            ->join('shifts', 'shifts.id', '=', 'shift_assignments.shift_id')
            ->join('stores', 'stores.id', '=', 'shifts.store_id')
            ->leftJoin('humanity_positions', 'humanity_positions.humanity_position_id', '=', 'shifts.humanity_position_id')
            ->where('shift_assignments.status', 'assigned')
            ->whereNull('shift_assignments.deleted_at')
            ->whereNull('shifts.deleted_at');
    }

    public function storeColumn(): string
    {
        return 'shifts.store_id';
    }

    public function dateColumn(): string
    {
        return 'shifts.shift_date';
    }

    public function heavy(): bool
    {
        return true;
    }
}
