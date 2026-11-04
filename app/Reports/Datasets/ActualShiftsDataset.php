<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Models\ActualShift;
use App\Reports\Contract\Column;
use App\Reports\Contract\ReportDataset;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Worked shifts: one row per roll-up in `actual_shifts` (a shift may sit on
 * several TCP segments). `duration_minutes` of a shift still on the clock is
 * "so far"; filter on is_open to leave those out. The old single `status` was
 * split into two axes, so there are two columns: what a manager asserted
 * (review_state) and how the time compares with the plan (time_variance).
 */
class ActualShiftsDataset extends ReportDataset
{
    public const KEY = 'operations.actual_shifts';

    public function label(): string
    {
        return 'Worked shifts';
    }

    public function grain(): array
    {
        return ['store', 'date', 'employee'];
    }

    public function columns(): array
    {
        return [
            new Column('store', 'Store', 'string', 'stores.store_number', groupable: true),
            new Column('date', 'Shift date', 'date', 'actual_shifts.shift_date', groupable: true),
            new Column('employee', 'Employee', 'integer', 'actual_shifts.employee_id', groupable: true, aggregations: ['count_distinct']),
            new Column('review_state', 'Review status', 'enum', 'actual_shifts.review_state', options: [
                ['value' => ActualShift::REVIEW_UNREVIEWED, 'label' => 'Unreviewed'],
                ['value' => ActualShift::REVIEW_WORKED, 'label' => 'Worked'],
                ['value' => ActualShift::REVIEW_ABSENT, 'label' => 'Absent'],
            ], groupable: true),
            new Column('time_variance', 'Variance against plan', 'enum', 'actual_shifts.time_variance', options: [
                ['value' => ActualShift::VARIANCE_MATCHES, 'label' => 'Matches the plan'],
                ['value' => ActualShift::VARIANCE_DIFFERS, 'label' => 'Differs from the plan'],
                ['value' => ActualShift::VARIANCE_UNPLANNED, 'label' => 'Unplanned'],
            ], groupable: true),
            new Column('shift_type', 'Shift type', 'enum', 'actual_shifts.shift_type', options: [
                ['value' => 'morning', 'label' => 'Morning'],
                ['value' => 'evening', 'label' => 'Evening'],
                ['value' => 'night', 'label' => 'Night'],
                ['value' => 'split', 'label' => 'Split'],
                ['value' => 'custom', 'label' => 'Custom'],
            ], groupable: true),
            new Column('is_open', 'Still on the clock', 'boolean', 'actual_shifts.is_open', groupable: true),
            new Column('needs_attention', 'Needs attention', 'boolean', 'actual_shifts.needs_attention', groupable: true),
            new Column('duration_minutes', 'Worked minutes', 'integer', 'actual_shifts.duration_minutes', filterable: false, measure: true, aggregations: ['sum']),
            new Column('count', 'Shifts', 'integer', 'actual_shifts.id', filterable: false, measure: true, aggregations: ['count']),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('actual_shifts')
            ->join('stores', 'stores.id', '=', 'actual_shifts.store_id')
            ->whereNull('actual_shifts.deleted_at');
    }

    public function storeColumn(): string
    {
        return 'actual_shifts.store_id';
    }

    public function dateColumn(): string
    {
        return 'actual_shifts.shift_date';
    }

    public function heavy(): bool
    {
        return true;
    }
}
