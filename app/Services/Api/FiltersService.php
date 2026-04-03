<?php

namespace App\Services\Api;

use App\Models\EmployeeSkill;
use App\Models\MasterSchedule;
use App\Models\Schedule;
use Illuminate\Database\Eloquent\Collection;

class FiltersService
{
    // ✅ فلتر الماستر (يرجع Query)
    public function getPublishedFlexibleQuery(array $data)
    {
        $query = MasterSchedule::query()
            ->where('published', true);

        // 🟢 start + end
        if (!empty($data['start_date']) && !empty($data['end_date'])) {
            $query->where(function ($q) use ($data) {
                $q->whereDate('start_date', '<=', $data['end_date'])
                  ->whereDate('end_date', '>=', $data['start_date']);
            });
        }

        // 🟡 start فقط
        elseif (!empty($data['start_date'])) {
            $query->whereDate('end_date', '>=', $data['start_date']);
        }

        // 🔵 end فقط
        elseif (!empty($data['end_date'])) {
            $query->whereDate('start_date', '<=', $data['end_date']);
        }

        // 🎯 store
        if (!empty($data['store_id'])) {
            $query->where('store_id', $data['store_id']);
        }

        return $query;
    }

    // ✅ فلتر الموظف (يعدل على Query موجود)
    public function filterSchedulesByEmployeeQuery($query, array $data)
    {
        if (!empty($data['employee_id'])) {
            $query->where('employee_id', $data['employee_id']);
        }

        return $query;
    }
} 