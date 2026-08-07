<?php

namespace App\Services\Scheduling;

use App\Models\ScheduleTemplate;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Week templates. Entirely local — a template is a plan, not a schedule, so
 * nothing here touches Humanity until it is applied.
 */
class ScheduleTemplateService
{
    public function __construct(
        private readonly WeekResolver $weeks,
        private readonly ShiftQueryService $shifts,
        private readonly ShiftTimeResolver $times,
    ) {
    }

    /** Snapshot a week into a reusable template. */
    public function createFromWeek(Store $store, string $name, ?string $description, string $weekStartInput, ?int $userId = null): ScheduleTemplate
    {
        $settings = $store->settings();
        $weekStart = $this->weeks->normalizeWeekStart($weekStartInput, $this->weeks->weekStartDow($settings));
        $weekEnd = $weekStart->addDays(6);

        $assignments = $this->shifts->assignmentsForRange($store, $weekStart->toDateString(), $weekEnd->toDateString());

        return DB::transaction(function () use ($store, $name, $description, $weekStart, $assignments, $userId) {
            $template = ScheduleTemplate::query()->create([
                'store_id' => $store->id,
                'name' => $name,
                'description' => $description,
                'created_by_user_id' => $userId,
            ]);

            $totalMinutes = 0;
            $count = 0;

            foreach ($assignments as $assignment) {
                $shift = $assignment->shift;

                if ($shift === null) {
                    continue;
                }

                $dayIndex = $this->weeks->dayIndexFor(CarbonImmutable::parse($shift->shift_date), $weekStart);

                if ($dayIndex === null) {
                    continue;
                }

                $template->shifts()->create([
                    'employee_id' => $assignment->employee_id,
                    // day_index, not a date: that is what makes it re-appliable.
                    'day_index' => $dayIndex,
                    'start_time' => $shift->start_time,
                    'end_time' => $shift->end_time,
                    'label' => $shift->label,
                    'shift_type' => $shift->shift_type,
                    'note' => $shift->note,
                ]);

                $totalMinutes += (int) $shift->duration_minutes;
                $count++;
            }

            $template->update(['shift_count' => $count, 'total_minutes' => $totalMinutes]);

            return $template->fresh('shifts');
        });
    }

    /**
     * Template rows turned into concrete shift payloads for a target week.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toShiftPayloads(ScheduleTemplate $template, CarbonImmutable $weekStart): array
    {
        return $template->shifts->map(function ($row) use ($weekStart) {
            return [
                'employee_id' => (int) $row->employee_id,
                'shift_date' => $weekStart->addDays((int) $row->day_index)->toDateString(),
                'start_time' => substr((string) $row->start_time, 0, 5),
                'end_time' => substr((string) $row->end_time, 0, 5),
                'label' => $row->label,
                'shift_type' => $row->shift_type ?? 'custom',
                'note' => $row->note,
            ];
        })->filter(fn (array $payload) => $payload['employee_id'] > 0)->values()->all();
    }

    public function present(ScheduleTemplate $template, bool $withShifts = false): array
    {
        $data = [
            'id' => (string) $template->id,
            'name' => $template->name,
            'description' => $template->description,
            'created_at' => $template->created_at?->toIso8601String(),
            'shift_count' => (int) $template->shift_count,
            'total_hours' => round((int) $template->total_minutes / 60, 2),
        ];

        if ($withShifts) {
            $data['shifts'] = $template->shifts->map(fn ($row) => [
                'employee_id' => (string) $row->employee_id,
                'day_index' => (int) $row->day_index,
                'start_time' => substr((string) $row->start_time, 0, 5),
                'end_time' => substr((string) $row->end_time, 0, 5),
                'label' => $row->label,
                'type' => $row->shift_type,
                'note' => $row->note,
            ])->values()->all();
        }

        return $data;
    }
}
