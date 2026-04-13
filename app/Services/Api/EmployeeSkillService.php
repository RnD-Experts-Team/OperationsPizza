<?php

namespace App\Services\Api;

use App\Models\EmployeeSkill;
use Illuminate\Database\Eloquent\Collection;

class EmployeeSkillService
{
    public function getAll(int $storeId)
    {
        $records = EmployeeSkill::with(['employee', 'skill'])
            ->whereHas('employee', function ($q) use ($storeId) {
                $q->where('store_id', $storeId);
            })
            ->get();

        return $records->groupBy('employee_id')->map(function ($items) {
            $employee = $items->first()->employee;

            return [
                'employee_id' => $employee->id,
                'employee_name' => $employee->name,
                'email' => $employee->email,
                'phone' => $employee->phone,

                'skills' => $items->map(function ($item) {
                    return [
                        'skill_id' => $item->skill->id,
                        'skill_name' => $item->skill->name,
                        'rating' => $item->rating,
                    ];
                })->values()
            ];
        })->values();
    }

    public function getById(int $id, int $storeId): EmployeeSkill
    {
        return EmployeeSkill::with(['employee', 'skill'])
            ->where('id', $id)
            ->whereHas('employee', function ($q) use ($storeId) {
                $q->where('store_id', $storeId);
            })
            ->firstOrFail();
    }

    public function create(array $data, int $storeId): EmployeeSkill
    {
        \App\Models\Employee::where('id', $data['employee_id'])
            ->where('store_id', $storeId)
            ->firstOrFail();

        return EmployeeSkill::create($data);
    }

    public function update(EmployeeSkill $employeeSkill, array $data): EmployeeSkill
    {
        $employeeSkill->update($data);
        return $employeeSkill->fresh();
    }

    public function delete(EmployeeSkill $employeeSkill): bool
    {
        return $employeeSkill->delete();
    }
}