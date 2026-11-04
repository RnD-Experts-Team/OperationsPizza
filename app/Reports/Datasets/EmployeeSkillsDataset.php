<?php

declare(strict_types=1);

namespace App\Reports\Datasets;

use App\Reports\Contract\Column;
use App\Reports\Contract\ReportDataset;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Skill ratings now (a snapshot: the period is ignored): one row per active
 * employee, store they belong to and skill they are rated on. A rating is the
 * person's, shared by every store they work at (employee_skills is unique per
 * employee and skill), so an employee at several stores appears once per
 * store with the same rating. That is the skills overview's rule: it lists an
 * employee under every store membership and a store filter narrows by
 * membership. Skills they are not rated on have no row. There is no skill
 * category, and the rating history is not kept (only the last rating and when
 * it was saved).
 */
class EmployeeSkillsDataset extends ReportDataset
{
    public const KEY = 'operations.employee_skills';

    public function label(): string
    {
        return 'Employee skills';
    }

    public function grain(): array
    {
        return ['store', 'employee'];
    }

    public function columns(): array
    {
        $name = DB::connection()->getDriverName() === 'mysql'
            ? "TRIM(CONCAT(employees.first_name, ' ', employees.last_name))"
            : "TRIM(employees.first_name || ' ' || employees.last_name)";

        return [
            new Column('store', 'Store', 'string', 'employee_store.store_number', groupable: true),
            new Column('employee', 'Employee', 'integer', 'employee_skills.employee_id', groupable: true, aggregations: ['count_distinct']),
            new Column('employee_name', 'Employee name', 'string', $name, groupable: true),
            new Column('skill', 'Skill', 'string', 'skills.name', groupable: true),
            new Column('rating', 'Rating (1 to 5)', 'integer', 'employee_skills.rating', groupable: true, measure: true, aggregations: ['avg', 'min', 'max']),
            new Column('rated_on', 'Rated on (UTC date)', 'date', 'DATE(employee_skills.updated_at)', groupable: true),
            new Column('count', 'Ratings', 'integer', 'employee_skills.id', filterable: false, measure: true, aggregations: ['count']),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('employee_skills')
            ->join('skills', 'skills.id', '=', 'employee_skills.skill_id')
            ->join('employees', 'employees.id', '=', 'employee_skills.employee_id')
            ->join('employee_store', 'employee_store.employee_id', '=', 'employees.id')
            ->where('employees.active', true)
            ->whereNull('employees.deleted_at');
    }

    /** Membership is matched on the store code, as the overview does. */
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
}
