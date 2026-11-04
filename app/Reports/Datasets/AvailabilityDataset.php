<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Contract\Column;
use App\Reports\Contract\ReportDataset;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Stated availability now (a snapshot: the period is ignored), from the hiring
 * profile: one row per active employee, store they belong to, weekday and
 * available window. The profile describes the person, so an employee at
 * several stores appears under each (as in the skills overview). A day on file
 * with no hours means available all day and counts 1440 minutes. Windows are
 * summed as listed, not merged, so overlapping ones (an AM and an OP window)
 * add up twice; a window ending at or before its start runs past midnight.
 * Manager-entered blocks (schedule_availability_overrides) are not included.
 */
class AvailabilityDataset extends ReportDataset
{
    public const KEY = 'operations.availability';

    public function label(): string
    {
        return 'Availability';
    }

    public function grain(): array
    {
        return ['store', 'employee'];
    }

    public function columns(): array
    {
        return [
            new Column('store', 'Store', 'string', 'employee_store.store_number', groupable: true),
            new Column('employee', 'Employee', 'integer', 'employee_availability_days.employee_id', groupable: true, aggregations: ['count_distinct']),
            new Column('weekday', 'Weekday', 'enum', 'LOWER(employee_availability_days.day_of_week)', options: array_map(
                fn (string $day) => ['value' => $day, 'label' => ucfirst($day)],
                ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
            ), groupable: true),
            new Column('shift_type', 'Shift type', 'enum', 'employee_availability_days.shift_type', options: [
                ['value' => 'AM', 'label' => 'AM'],
                ['value' => 'PM', 'label' => 'PM'],
                ['value' => 'OP', 'label' => 'Open'],
            ], groupable: true),
            new Column('available_minutes', 'Available minutes', 'integer', $this->minutesSql(), filterable: false, measure: true, aggregations: ['sum']),
            new Column('windows', 'Windows', 'integer', 'employee_availability_days.id', filterable: false, measure: true, aggregations: ['count']),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('employee_availability_days')
            ->join('employees', 'employees.id', '=', 'employee_availability_days.employee_id')
            ->join('employee_store', 'employee_store.employee_id', '=', 'employees.id')
            ->leftJoin('employee_availability_times', 'employee_availability_times.employee_availability_day_id', '=', 'employee_availability_days.id')
            ->where('employees.active', true)
            ->whereNull('employees.deleted_at');
    }

    public function storeColumn(): string
    {
        return 'employee_store.store_number';
    }

    public function storeKeys(array $codes): array
    {
        return $codes;
    }

    public function dateColumn(): ?string
    {
        return null;
    }

    /** Length of the window in minutes, the same on MySQL and SQLite. */
    private function minutesSql(): string
    {
        $of = fn (string $column) => "(CAST(SUBSTR(CAST({$column} AS CHAR), 1, 2) AS SIGNED) * 60 + CAST(SUBSTR(CAST({$column} AS CHAR), 4, 2) AS SIGNED))";
        $from = $of('employee_availability_times.available_from');
        $to = $of('employee_availability_times.available_to');

        return "CASE WHEN employee_availability_times.id IS NULL THEN 1440 WHEN {$to} > {$from} THEN {$to} - {$from} ELSE {$to} - {$from} + 1440 END";
    }
}
