<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Contract\Column;
use App\Reports\Contract\ReportDataset;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Leave requests, dated by the day they start (a leave is a date range).
 * Leave with no store has an empty store column and never matches a store
 * filter.
 */
class TimeOffDataset extends ReportDataset
{
    public const KEY = 'operations.time_off';

    public function label(): string
    {
        return 'Time off';
    }

    public function grain(): array
    {
        return ['store', 'date', 'employee'];
    }

    public function columns(): array
    {
        return [
            new Column('store', 'Store', 'string', 'stores.store_number', groupable: true),
            new Column('date', 'First day', 'date', 'time_off.start_date', groupable: true),
            new Column('employee', 'Employee', 'integer', 'time_off.employee_id', groupable: true, aggregations: ['count_distinct']),
            new Column('type', 'Type', 'enum', 'time_off.type', options: [
                ['value' => 'pto', 'label' => 'PTO'],
                ['value' => 'vacation', 'label' => 'Vacation'],
                ['value' => 'sick', 'label' => 'Sick'],
                ['value' => 'unpaid', 'label' => 'Unpaid'],
                ['value' => 'other', 'label' => 'Other'],
            ], groupable: true),
            new Column('status', 'Status', 'enum', 'time_off.status', options: [
                ['value' => 'pending', 'label' => 'Pending'],
                ['value' => 'approved', 'label' => 'Approved'],
                ['value' => 'denied', 'label' => 'Denied'],
            ], groupable: true),
            new Column('count', 'Requests', 'integer', 'time_off.id', filterable: false, measure: true, aggregations: ['count']),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('time_off')
            ->leftJoin('stores', 'stores.id', '=', 'time_off.store_id')
            ->whereNull('time_off.deleted_at');
    }

    public function storeColumn(): string
    {
        return 'time_off.store_id';
    }

    public function dateColumn(): string
    {
        return 'time_off.start_date';
    }
}
