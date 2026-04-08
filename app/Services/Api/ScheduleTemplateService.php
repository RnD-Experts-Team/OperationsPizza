<?php

namespace App\Services\Api;

use App\Models\ScheduleTemplate;
use App\Models\ScheduleTemplateDetail;
use App\Models\MasterSchedule;
use App\Models\ScheduleTemplateStore;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ScheduleTemplateService
{

    public function getAllPaginated(int $perPage = 10, int $storeId = null)
    {
        return ScheduleTemplate::with('details')
            ->when($storeId, function ($q) use ($storeId) {
                $q->whereHas('stores', function ($q2) use ($storeId) {
                    $q2->where('store_id', $storeId);
                });
            })
            ->orderBy('id', 'asc')
            ->paginate($perPage);
    }
    public function saveTemplate(array $data, int $userId): ScheduleTemplate
    {
        return DB::transaction(function () use ($data, $userId) {

            $template = ScheduleTemplate::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'created_by' => $userId,
            ]);

            // ====================================
            // 🔴 CASE 1: Export from Schedule
            // ====================================
            if (!empty($data['master_schedule_id'])) {

                $master = MasterSchedule::with('schedules')
                    ->findOrFail($data['master_schedule_id']);

                if ($master->schedules->isEmpty()) {
                    throw ValidationException::withMessages([
                        'schedules' => ['No schedules found']
                    ]);
                }

                foreach ($master->schedules as $schedule) {

                    $day = strtolower(
                        Carbon::parse($schedule->date)->format('l')
                    );

                    $template->details()->create([
                        'employee_id' => $schedule->employee_id,
                        'day_of_week' => $day,
                        'start_time' => $schedule->start_time,
                        'end_time' => $schedule->end_time,
                        'skill_id' => $schedule->skill_id,
                    ]);
                }

                // 🔥 نحفظ store_id تلقائي
                ScheduleTemplateStore::create([
                    'schedule_template_id' => $template->id,
                    'store_id' => $master->store_id,
                ]);
            }

            // ====================================
            // 🟢 CASE 2: General Template
            // ====================================
            else {

                foreach ($data['details'] as $detail) {

                    $template->details()->create([
                        'day_of_week' => $detail['day_of_week'],
                        'start_time' => $detail['start_time'],
                        'end_time' => $detail['end_time'],
                        'skill_id' => $detail['skill_id'],
                    ]);
                }
            }

            return $template->load(['details', 'stores']);
        });
    }
    

    public function loadTemplatePreview(array $data): array
    {
        $template = ScheduleTemplate::with('details')
            ->findOrFail($data['template_id']);

        $shifts = [];

        foreach ($template->details as $detail) {

            $date = $this->mapDayToDate(
                $detail->day_of_week,
                $data['start_date']
            );

            $shifts[] = [
                'date' => $date,
                'start_time' => $detail->start_time,
                'end_time' => $detail->end_time,
                'skill_id' => $detail->skill_id,
                'employee_id' => null,
                'source' => 'template'
            ];
        }

        return [
            'template_shifts' => $shifts
        ];
    }
   public function getById(int $id, int $storeId = null): ScheduleTemplate
    {
        return ScheduleTemplate::with('details')
            ->when($storeId, function ($q) use ($storeId) {
                $q->whereHas('stores', function ($q2) use ($storeId) {
                    $q2->where('store_id', $storeId);
                });
            })
            ->findOrFail($id);
    }

     public function delete(ScheduleTemplate $template): bool
    {
        return $template->delete(); // soft delete
    }
    public function forceDelete(int $id, int $storeId = null): bool
    {
    $template = ScheduleTemplate::withTrashed()
        ->when($storeId, function ($q) use ($storeId) {
            $q->whereHas('stores', function ($q2) use ($storeId) {
                $q2->where('store_id', $storeId);
            });
        })
        ->findOrFail($id);

    return $template->forceDelete();
    }
   public function restore(int $id, int $storeId = null): bool
    {
        $template = ScheduleTemplate::withTrashed()
            ->when($storeId, function ($q) use ($storeId) {
                $q->whereHas('stores', function ($q2) use ($storeId) {
                    $q2->where('store_id', $storeId);
                });
            })
            ->findOrFail($id);

        return $template->restore();
    }
    private function mapDayToDate(string $day, string $startDate): string
    {
        $start = \Carbon\Carbon::parse($startDate);

        $map = [
            'tuesday' => 0,
            'wednesday' => 1,
            'thursday' => 2,
            'friday' => 3,
            'saturday' => 4,
            'sunday' => 5,
            'monday' => 6,
        ];

        return $start->copy()->addDays($map[$day])->toDateString();
    }

     
}