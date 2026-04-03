<?php

namespace App\Services\Api;

use App\Models\Employee;
use App\Models\MasterSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use App\Models\DayOff;
use App\Models\Availability;
use App\Models\Schedule;
use App\Models\Skill;

class MasterScheduleService
{
    

    public function initScheduling(array $data): array
    {
        // published schedule

        $publishedSchedule = MasterSchedule::with([
                'schedules.employee',
                'schedules.skill'
            ])
            ->where('store_id', $data['store_id'])
            ->where('published', true)
            ->where(function ($query) use ($data) {
                $query->whereDate('start_date', '<=', $data['end_date'])
                      ->whereDate('end_date', '>=', $data['start_date']);
            })
            ->latest('start_date')
            ->first();

        // days off
        $daysOff = DayOff::with('employee')
            ->whereHas('employee', function ($query) use ($data) {
                $query->where('store_id', $data['store_id']);
            })
            ->whereBetween('date', [$data['start_date'], $data['end_date']])
            ->where('acceptedStatus', 'approved')
            ->get();

        // employees + availability + times + employee skills
        $employees = Employee::with([
                'availability.times',
                'skills'
            ])
            ->where('store_id', $data['store_id'])
            ->get();

        // all skills
        $skills = Skill::get();

        return [
            'published_schedule' => $publishedSchedule,
            'days_off' => $daysOff,
            'employees' => $employees,
            'skills' => $skills,
        ];
    }
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

            $this->validateEmployeesBelongToStore(
                $data['schedules'],
                $data['store_id']
            );
            $this->validateEmployeeNotOnDayOff($data['schedules']);
            $this->validateEmployeeAvailability($data['schedules']);
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
                    'edited_by' => $userId,
                ]);
            }

            return $master->load('schedules');
        });
    }

    public function updateWithSchedules(MasterSchedule $master, array $data, ?int $userId = null): MasterSchedule
    {
        if ($master->published) {
            throw ValidationException::withMessages([
                'published' => ['Cannot modify a published schedule.']
            ]);
        }

        return DB::transaction(function () use ($master, $data, $userId) {

            $storeId = $data['store_id'] ?? $master->store_id;
            $startDate = $data['start_date'] ?? $master->start_date;
            $endDate = $data['end_date'] ?? $master->end_date;

            $this->ensureNoMasterOverlap($storeId, $startDate, $endDate, $master->id);

            // 🔥 update master
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

            // 🔥 schedules
            if (array_key_exists('schedules', $data)) {

                $this->validateEmployeesBelongToStore($data['schedules'], $storeId);
                $this->validateEmployeeNotOnDayOff($data['schedules']);
                $this->validateEmployeeAvailability($data['schedules']);

                $this->validateSchedulesBusinessRules(
                    $data['schedules'],
                    $startDate,
                    $endDate
                );

                $existingSchedules = $master->schedules()->get()->keyBy('id');

                $incomingIds = collect($data['schedules'])
                    ->pluck('id')
                    ->filter()
                    ->toArray();

                $master->schedules()
                    ->whereNotIn('id', $incomingIds)
                    ->delete();

                foreach ($data['schedules'] as $schedule) {

                    if (isset($schedule['id']) && $existingSchedules->has($schedule['id'])) {

                        $existingSchedules[$schedule['id']]->update([
                            'employee_id' => $schedule['employee_id'] ?? null,
                            'date' => $schedule['date'],
                            'start_time' => $schedule['start_time'],
                            'end_time' => $schedule['end_time'],
                            'actual_start_time' => $schedule['actual_start_time'] ?? null,
                            'actual_end_time' => $schedule['actual_end_time'] ?? null,
                            'skill_id' => $schedule['skill_id'],
                            'edited_by' => $userId,
                        ]);

                    } else {

                        $master->schedules()->create([
                            'employee_id' => $schedule['employee_id'] ?? null,
                            'date' => $schedule['date'],
                            'start_time' => $schedule['start_time'],
                            'end_time' => $schedule['end_time'],
                            'actual_start_time' => $schedule['actual_start_time'] ?? null,
                            'actual_end_time' => $schedule['actual_end_time'] ?? null,
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
    public function unpublish(MasterSchedule $master): MasterSchedule
    {
        if (!$master->published) {
            throw ValidationException::withMessages([
                'published' => ['This master schedule is already unpublished.'],
            ]);
        }

        $master->update([
            'published' => false,
            'published_by' => null,
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
    public function deleteSchedule(int $id): void
    {
        $schedule = Schedule::findOrFail($id);

        if ($schedule->masterSchedule && $schedule->masterSchedule->published) {
            throw ValidationException::withMessages([
                'schedule' => ['Cannot delete schedule from a published master schedule.'],
            ]);
        }

        if ($schedule->actual_start_time || $schedule->actual_end_time) {
            throw ValidationException::withMessages([
                'schedule' => ['Cannot delete a schedule that has actual time recorded.'],
            ]);
        }

        $schedule->delete();
    }
    public function restoreSchedule(int $id): Schedule
    {
        $schedule = Schedule::withTrashed()->findOrFail($id);

        // 🔴 لازم يكون محذوف
        if (!$schedule->trashed()) {
            throw ValidationException::withMessages([
                'restore' => ['Schedule is not deleted.']
            ]);
        }

        $schedule->restore();

        return $schedule->fresh();
    }
    public function forceDeleteSchedule(int $id): void
    {
        $schedule = Schedule::withTrashed()->findOrFail($id);

        // 🔴 لازم يكون soft deleted أولاً
        if (!$schedule->trashed()) {
            throw ValidationException::withMessages([
                'force_delete' => ['You must delete the schedule first before force deleting.']
            ]);
        }

        $schedule->forceDelete();
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
    public function copySchedule(array $data, int $userId): MasterSchedule
    {
        return DB::transaction(function () use ($data, $userId) {

            // 🟢 1. تحديد المصدر
            if (!empty($data['master_schedule_id'])) {

                // 🔵 المستخدم اختار جدول معين
                $source = MasterSchedule::with('schedules')
                    ->findOrFail($data['master_schedule_id']);

            } else {

                // 🟡 الافتراضي = الأسبوع الماضي
                $start = Carbon::parse($data['start_date'])->subWeek();
                $end = Carbon::parse($data['end_date'])->subWeek();

                $source = MasterSchedule::with('schedules')
                    ->where('store_id', $data['store_id'])
                    ->whereDate('start_date', $start)
                    ->whereDate('end_date', $end)
                    ->first();

                if (!$source) {
                    throw ValidationException::withMessages([
                        'previous' => ['No previous week found.']
                    ]);
                }
            }

            // 🟢 2. إنشاء master جديد
            $newMaster = MasterSchedule::create([
                'store_id' => $data['store_id'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'published' => false,
                'created_by' => $userId,
            ]);

            // 🟡 3. نسخ الشفتات
            foreach ($source->schedules as $schedule) {

                $diffDays = Carbon::parse($data['start_date'])
                    ->diffInDays(Carbon::parse($source->start_date), false);

                $newDate = Carbon::parse($schedule->date)->addDays($diffDays);

                $newMaster->schedules()->create([
                    'employee_id' => $schedule->employee_id,
                    'date' => $newDate->toDateString(),
                    'start_time' => $schedule->start_time,
                    'end_time' => $schedule->end_time,
                    'actual_start_time' => null,
                    'actual_end_time' => null,
                    'skill_id' => $schedule->skill_id,
                    'edited_by' => $userId,
                ]);
            }

            return $newMaster->load('schedules');
        });
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
    private function validateEmployeesBelongToStore(array $schedules, int $storeId): void
    {
        foreach ($schedules as $index => $schedule) {
            if (!empty($schedule['employee_id'])) {
                $employeeExistsInStore = Employee::where('id', $schedule['employee_id'])
                    ->where('store_id', $storeId)
                    ->exists();

                if (!$employeeExistsInStore) {
                    throw ValidationException::withMessages([
                        "schedules.$index.employee_id" => [
                            'The selected employee does not belong to the same store as this master schedule.'
                        ],
                    ]);
                }
            }
        }
    }

    private function validateEmployeeNotOnDayOff(array $schedules): void
    {
        foreach ($schedules as $index => $schedule) {

            if (empty($schedule['employee_id'])) {
                continue;
            }

            $hasDayOff = DayOff::where('employee_id', $schedule['employee_id'])
                ->whereDate('date', $schedule['date'])
                ->where('acceptedStatus', 'approved')
                ->whereIn('type', ['sick day', 'unavailable', 'pto', 'vto'])
                ->exists();

            if ($hasDayOff) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "schedules.$index.employee_id" => [
                        'This employee has an approved day off on this date and cannot be scheduled.'
                    ],
                ]);
            }
        }
    }
 

    private function validateEmployeeAvailability(array $schedules): void
    {
        foreach ($schedules as $index => $schedule) {

            if (empty($schedule['employee_id'])) {
                continue;
            }

            $date = Carbon::parse($schedule['date']);
            $dayName = strtolower($date->format('l')); // monday

            // 🔍 هل عنده availability بهذا اليوم؟
            $availability = Availability::with('times')
                ->where('employee_id', $schedule['employee_id'])
                ->where('day_of_week', $dayName)
                ->first();

            if (!$availability) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "schedules.$index.employee_id" => [
                        'Employee is not available on this day.'
                    ],
                ]);
            }

            $start = Carbon::createFromFormat('H:i', $schedule['start_time']);
            $end = Carbon::createFromFormat('H:i', $schedule['end_time']);

            $isInsideAnyRange = false;

            foreach ($availability->times as $time) {

                $from = Carbon::createFromFormat('H:i:s', $time->from);
                $to = Carbon::createFromFormat('H:i:s', $time->to);

                // 🔥 لازم يكون كامل الشفت ضمن نفس الفترة
                if ($start->gte($from) && $end->lte($to)) {
                    $isInsideAnyRange = true;
                    break;
                }
            }

            if (!$isInsideAnyRange) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "schedules.$index.start_time" => [
                        'Employee is not available during this time range.'
                    ],
                ]);
            }
        }
    }

    
}