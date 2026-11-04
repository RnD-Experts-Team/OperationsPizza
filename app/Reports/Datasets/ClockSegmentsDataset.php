<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Contract\Column;
use App\Reports\Contract\ReportDataset;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The raw clock: one row per TCP work segment (`tcp_work_segments`, each a
 * paid stretch at one store; a break is the gap between two segments and is
 * not counted). Dated by the store's local day the segment started, from the
 * wall-clock punch-in. `minutes` is TCP's duration; a segment still open has
 * none yet, so it adds a count and no minutes (filter on is_open to leave
 * those out).
 */
class ClockSegmentsDataset extends ReportDataset
{
    public const KEY = 'operations.clock_segments';

    public function label(): string
    {
        return 'Clock segments';
    }

    public function grain(): array
    {
        return ['store', 'date', 'employee'];
    }

    public function columns(): array
    {
        return [
            new Column('store', 'Store', 'string', 'stores.store_number', groupable: true),
            new Column('date', 'Work date (store local)', 'date', 'DATE(tcp_work_segments.time_in)', groupable: true),
            new Column('employee', 'Employee', 'integer', 'tcp_work_segments.employee_id', groupable: true, aggregations: ['count_distinct']),
            new Column('job_code', 'Job code', 'string', 'COALESCE(tcp_job_codes.description, tcp_work_segments.tcp_job_code_id)', groupable: true),
            new Column('origin', 'Origin', 'enum', 'tcp_work_segments.origin', options: [
                ['value' => 'punch', 'label' => 'Clocked in the app'],
                ['value' => 'manual', 'label' => 'Entered by a manager'],
                ['value' => 'discovered', 'label' => 'Found in TCP'],
            ], groupable: true),
            new Column('is_open', 'Still on the clock', 'boolean', 'CASE WHEN tcp_work_segments.time_out IS NULL THEN 1 ELSE 0 END', groupable: true),
            new Column('minutes', 'Worked minutes', 'integer', 'tcp_work_segments.duration_minutes', filterable: false, measure: true, aggregations: ['sum']),
            new Column('count', 'Segments', 'integer', 'tcp_work_segments.id', filterable: false, measure: true, aggregations: ['count']),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('tcp_work_segments')
            ->join('stores', 'stores.id', '=', 'tcp_work_segments.store_id')
            ->leftJoin('tcp_job_codes', 'tcp_job_codes.tcp_job_code_id', '=', 'tcp_work_segments.tcp_job_code_id')
            ->whereNull('tcp_work_segments.deleted_at');
    }

    public function storeColumn(): string
    {
        return 'tcp_work_segments.store_id';
    }

    public function dateColumn(): ?string
    {
        return 'DATE(tcp_work_segments.time_in)';
    }

    public function heavy(): bool
    {
        return true;
    }
}
