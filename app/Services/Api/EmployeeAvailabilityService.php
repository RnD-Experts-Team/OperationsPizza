<?php

namespace App\Services\Api;

use Illuminate\Support\Facades\DB;
use App\Models\Availability;
use App\Models\AvailabilityTime;
use App\Models\Employee;

class EmployeeAvailabilityService
{
    public function getAll(int $storeId)
    {
        $data = Availability::query()
            ->with([
                'employee',
                'times' => function ($query) {
                    $query->orderBy('from', 'asc');
                },
            ])
            ->whereHas('employee', function ($query) use ($storeId) {
                $query->where('store_id', $storeId);
            })
            ->join('employees', 'availabilities.employee_id', '=', 'employees.id')
            ->orderBy('availabilities.employee_id', 'asc')
            ->orderByRaw("
                CASE availabilities.day_of_week
                    WHEN 'monday' THEN 1
                    WHEN 'tuesday' THEN 2
                    WHEN 'wednesday' THEN 3
                    WHEN 'thursday' THEN 4
                    WHEN 'friday' THEN 5
                    WHEN 'saturday' THEN 6
                    WHEN 'sunday' THEN 7
                END
            ")
            ->select('availabilities.*')
            ->get();

        return $data->groupBy('employee_id')
            ->map(function ($items) {
                $employee = $items->first()->employee;

                return [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'store_id' => $employee->store_id,
                    'phone' => $employee->phone,
                    'email' => $employee->email,
                    'availabilities' => $items->map(function ($availability) {
                        return [
                            'id' => $availability->id,
                            'day_of_week' => $availability->day_of_week,
                            'times' => $availability->times->map(function ($time) {
                                return [
                                    'from' => $time->from,
                                    'to' => $time->to,
                                ];
                            })->values(),
                        ];
                    })->values(),
                ];
            })
            ->values();
    }

    public function getById(int $id, int $storeId)
    {
        return Availability::with(['employee', 'times'])
            ->where('id', $id)
            ->whereHas('employee', function ($query) use ($storeId) {
                $query->where('store_id', $storeId);
            })
            ->firstOrFail();
    }

    public function create(array $data, int $storeId)
    {
        return DB::transaction(function () use ($data, $storeId) {
            Employee::where('id', $data['employee_id'])
                ->where('store_id', $storeId)
                ->firstOrFail();

            $availability = Availability::create([
                'employee_id' => $data['employee_id'],
                'day_of_week' => $data['day_of_week'],
            ]);

            foreach ($data['times'] as $time) {
                AvailabilityTime::create([
                    'availability_id' => $availability->id,
                    'from' => $time['from'],
                    'to' => $time['to'],
                ]);
            }

            return $availability->load(['employee', 'times']);
        });
    }

    public function update($availability, array $data, int $storeId)
    {
        return DB::transaction(function () use ($availability, $data, $storeId) {
            $employeeId = $data['employee_id'] ?? $availability->employee_id;

            Employee::where('id', $employeeId)
                ->where('store_id', $storeId)
                ->firstOrFail();

            $availability->update([
                'employee_id' => $employeeId,
                'day_of_week' => $data['day_of_week'] ?? $availability->day_of_week,
            ]);

            $availability->times()->delete();

            foreach ($data['times'] as $time) {
                AvailabilityTime::create([
                    'availability_id' => $availability->id,
                    'from' => $time['from'],
                    'to' => $time['to'],
                ]);
            }

            return $availability->load(['employee', 'times']);
        });
    }

    public function delete($availability)
    {
        return DB::transaction(function () use ($availability) {
            $availability->times()->delete();
            return $availability->delete();
        });
    }
}