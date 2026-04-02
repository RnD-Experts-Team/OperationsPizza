<?php

namespace App\Services\Api;

use App\Models\MasterSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
class MasterScheduleService
{
    public function getAllPaginated(int $perPage = 10)
    {
        return MasterSchedule::with('schedules')
            ->orderBy('store_id')
            ->orderBy('start_date')
            ->paginate($perPage);
    }

    public function getById(int $id): MasterSchedule
    {
        return MasterSchedule::with('schedules')->findOrFail($id);
    }

    public function storeWithSchedules(array $data, int $userId): MasterSchedule
    {
        return DB::transaction(function () use ($data, $userId) {
            $this->ensureNoMasterOverlap(
                $data['store_id'],
                $data['start_date'],
                $data['end_date']
            );

            $this->validateSchedulesBusinessRules(
                $data['schedules'],
                $data['start_date'],
                $data['end_date']
            );

            $master = MasterSchedule::create([
                'store_id' => $data['store_id'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'published' => false,
                'created_by' => $userId,
            ]);

            foreach ($data['schedules'] as $schedule) {
                $master->schedules()->create([
                    'employee_id' => $schedule['employee_id'] ?? null,
                    'date' => $schedule['date'],
                    'start_time' => $schedule['start_time'],
                    'end_time' => $schedule['end_time'],
                    'actual_start_time' => null,
                    'actual_end_time' => null,
                    'skill_id' => $schedule['skill_id'],
                    'edited_by' => null,
                ]);
            }

            return $master->load('schedules');
        });
    }

    public function updateWithSchedules(MasterSchedule $master, array $data, ?int $userId = null): MasterSchedule
    {
         

        return DB::transaction(function () use ($master, $data, $userId) {

            $storeId = $data['store_id'] ?? $master->store_id;
            $startDate = $data['start_date'] ?? $master->start_date;
            $endDate = $data['end_date'] ?? $master->end_date;

            // 🔥 منع overlap
            $this->ensureNoMasterOverlap($storeId, $startDate, $endDate, $master->id);

            // 🔥 تحديث بيانات master
            $updateData = [];

            if (array_key_exists('store_id', $data)) {
                $updateData['store_id'] = $data['store_id'];
            }

            if (array_key_exists('start_date', $data)) {
                $updateData['start_date'] = $data['start_date'];
            }

            if (array_key_exists('end_date', $data)) {
                $updateData['end_date'] = $data['end_date'];
            }

             

            $master->update($updateData);

            // 🔥 تحديث schedules (SYNC بدل delete all)
            if (array_key_exists('schedules', $data)) {

                $this->validateSchedulesBusinessRules(
                    $data['schedules'],
                    $startDate,
                    $endDate
                );

                // 📌 existing schedules
                $existingSchedules = $master->schedules()->get()->keyBy('id');

                // 📌 IDs القادمة من frontend
                $incomingIds = collect($data['schedules'])
                    ->pluck('id')
                    ->filter()
                    ->toArray();

                // 🔥 حذف القديم فقط
                $master->schedules()
                    ->whereNotIn('id', $incomingIds)
                    ->delete();

                foreach ($data['schedules'] as $schedule) {

                    // 🔁 UPDATE
                    if (isset($schedule['id']) && $existingSchedules->has($schedule['id'])) {

                        $existingSchedules[$schedule['id']]->update([
                            'employee_id' => $schedule['employee_id'] ?? null,
                            'date' => $schedule['date'],
                            'start_time' => $schedule['start_time'],
                            'end_time' => $schedule['end_time'],
                            'skill_id' => $schedule['skill_id'],
                            'edited_by' => $userId,
                        ]);

                    } else {
                        // ➕ CREATE
                        $master->schedules()->create([
                            'employee_id' => $schedule['employee_id'] ?? null,
                            'date' => $schedule['date'],
                            'start_time' => $schedule['start_time'],
                            'end_time' => $schedule['end_time'],
                            'actual_start_time' => null,
                            'actual_end_time' => null,
                            'skill_id' => $schedule['skill_id'],
                            'edited_by' => $userId,
                        ]);
                    }
                }
            }

            return $master->load('schedules');
        });
    }

    public function publish(MasterSchedule $master,int $userId): MasterSchedule
    {
        // 🔴 إذا منشور مسبقًا
        if ($master->published) {
            throw ValidationException::withMessages([
                'published' => ['This master schedule is already published.'],
            ]);
        }

        // 🔥 لازم يكون فيه schedules
        if ($master->schedules()->count() === 0) {
            throw ValidationException::withMessages([
                'schedules' => ['Cannot publish an empty schedule.'],
            ]);
        }

        // 🔥 تحقق: كل يوم فيه schedule
        $dates = $this->generateWeekDates($master->start_date, $master->end_date);

        $existingDates = $master->schedules()
            ->pluck('date')
            ->unique()
            ->toArray();

        foreach ($dates as $date) {
            if (!in_array($date, $existingDates)) {
                throw ValidationException::withMessages([
                    'schedules' => ["Missing schedule for date: $date"],
                ]);
            }
        }

        // ✅ publish
        $master->update([
            'published' => true,
            'published_by' => $userId,
        ]);

        return $master->fresh()->load('schedules');
    }

    public function delete(MasterSchedule $master): void
    {
        DB::transaction(function () use ($master) {
            $master->schedules()->delete(); // soft delete schedules
            $master->delete(); // soft delete master
        });
    }
     public function restore(int $id): MasterSchedule
    {
        $master = MasterSchedule::withTrashed()->findOrFail($id);

        // 🔥 تحقق: لازم يكون محذوف
        if (!$master->trashed()) {
            throw ValidationException::withMessages([
                'restore' => ['Record is not deleted.']
            ]);
        }

        DB::transaction(function () use ($master) {
            $master->restore();
            $master->schedules()->withTrashed()->restore();
        });

        return $master->load('schedules');
    }
    public function forceDelete(int $id): void
    {
        $master = MasterSchedule::withTrashed()->findOrFail($id);

        // 🔥 تحقق: لازم يكون soft deleted أولاً
        if (!$master->trashed()) {
            throw ValidationException::withMessages([
                'force_delete' => ['You must delete the record first before force deleting.']
            ]);
        }

        DB::transaction(function () use ($master) {
            $master->schedules()->withTrashed()->forceDelete();
            $master->forceDelete();
        });
    }
    public function getTrashed()
    {
        return MasterSchedule::onlyTrashed()
            ->with('schedules')
            ->orderBy('store_id')
            ->orderBy('start_date')
            ->get();
    }

 
    public function generateWeekDates(string $startDate, string $endDate): array
    {
        $dates = [];
        $current = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();

        while ($current->lte($end)) {
            $dates[] = $current->toDateString();
            $current->addDay();
        }

        return $dates;
    }
    private function ensureNoMasterOverlap(
        int $storeId,
        string $startDate,
        string $endDate,
        ?int $ignoreId = null
        ): void {
        $query = MasterSchedule::where('store_id', $storeId)
            ->where(function ($q) use ($startDate, $endDate) {
                $q->where('start_date', '<=', $endDate)
                  ->where('end_date', '>=', $startDate);
            });

        if (!is_null($ignoreId)) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'date_range' => ['This store already has a master schedule in the selected date range.'],
            ]);
        }
    }
    private function validateSchedulesBusinessRules(
        array $schedules,
        string $masterStartDate,
        string $masterEndDate
        ): void {
        $employeeDailyShifts = [];
        $masterStart = Carbon::parse($masterStartDate)->startOfDay();
        $masterEnd = Carbon::parse($masterEndDate)->startOfDay();

        foreach ($schedules as $index => $schedule) {
            $date = Carbon::parse($schedule['date'])->startOfDay();
            $startTime = Carbon::createFromFormat('H:i', $schedule['start_time']);
            $endTime = Carbon::createFromFormat('H:i', $schedule['end_time']);
            $employeeId = $schedule['employee_id'] ?? null;

            if ($date->lt($masterStart) || $date->gt($masterEnd)) {
                throw ValidationException::withMessages([
                    "schedules.$index.date" => ['Schedule date must be within the master schedule date range.'],
                ]);
            }

            if ($endTime->lte($startTime)) {
                throw ValidationException::withMessages([
                    "schedules.$index.end_time" => ['end_time must be after start_time.'],
                ]);
            }

            if ($employeeId) {
                $key = $employeeId . '_' . $date->toDateString();

                if (!isset($employeeDailyShifts[$key])) {
                    $employeeDailyShifts[$key] = [];
                }

                foreach ($employeeDailyShifts[$key] as $existingShift) {
                    $existingStart = Carbon::createFromFormat('H:i', $existingShift['start_time']);
                    $existingEnd = Carbon::createFromFormat('H:i', $existingShift['end_time']);

                    $hasOverlap = $startTime->lt($existingEnd) && $endTime->gt($existingStart);

                    if ($hasOverlap) {
                        throw ValidationException::withMessages([
                            "schedules.$index.start_time" => [
                                'This employee has overlapping split shifts on the same day.'
                            ],
                        ]);
                    }
                }

                $employeeDailyShifts[$key][] = [
                    'start_time' => $schedule['start_time'],
                    'end_time' => $schedule['end_time'],
                ];
            }
        }
    }

    
}