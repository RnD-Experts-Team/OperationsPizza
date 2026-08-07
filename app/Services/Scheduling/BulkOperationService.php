<?php

namespace App\Services\Scheduling;

use App\Jobs\ProcessBulkOperationJob;
use App\Models\ScheduleBulkOperation;
use App\Models\ScheduleTemplate;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

/**
 * One UI action ("copy previous week") becomes ~70 Humanity calls, and Humanity
 * throttles on exactly that pattern. So bulk work is queued, reported per item,
 * and never rolled back — deleting shifts we already created to "undo" is worse
 * than a partial week, especially once employees have seen it.
 */
class BulkOperationService
{
    public function __construct(
        private readonly WeekResolver $weeks,
        private readonly ShiftQueryService $shifts,
        private readonly ScheduleTemplateService $templates,
    ) {
    }

    public function copyWeek(Store $store, string $sourceWeek, string $targetWeek, string $mode, ?int $userId): ScheduleBulkOperation
    {
        $settings = $store->settings();
        $dow = $this->weeks->weekStartDow($settings);

        $source = $this->weeks->normalizeWeekStart($sourceWeek, $dow);
        $target = $this->weeks->normalizeWeekStart($targetWeek, $dow);

        $assignments = $this->shifts->assignmentsForRange(
            $store,
            $source->toDateString(),
            $source->addDays(6)->toDateString()
        );

        $payloads = [];

        foreach ($assignments as $assignment) {
            $shift = $assignment->shift;

            if ($shift === null) {
                continue;
            }

            $dayIndex = $this->weeks->dayIndexFor(\Carbon\CarbonImmutable::parse($shift->shift_date), $source);

            if ($dayIndex === null) {
                continue;
            }

            $payloads[] = [
                'employee_id' => (int) $assignment->employee_id,
                'shift_date' => $target->addDays($dayIndex)->toDateString(),
                'start_time' => substr((string) $shift->start_time, 0, 5),
                'end_time' => substr((string) $shift->end_time, 0, 5),
                'label' => $shift->label,
                'shift_type' => $shift->shift_type,
                'note' => $shift->note,
            ];
        }

        return $this->queue($store, 'copy_week', $target->toDateString(), $payloads, $mode, $userId, [
            'source_week_start_date' => $source->toDateString(),
        ]);
    }

    public function applyTemplate(Store $store, ScheduleTemplate $template, string $targetWeek, string $mode, ?int $userId): ScheduleBulkOperation
    {
        $settings = $store->settings();
        $target = $this->weeks->normalizeWeekStart($targetWeek, $this->weeks->weekStartDow($settings));

        $payloads = $this->templates->toShiftPayloads($template->load('shifts'), $target);

        return $this->queue($store, 'apply_template', $target->toDateString(), $payloads, $mode, $userId, [
            'schedule_template_id' => $template->id,
        ]);
    }

    public function clearWeek(Store $store, string $week, ?int $userId): ScheduleBulkOperation
    {
        $settings = $store->settings();
        $target = $this->weeks->normalizeWeekStart($week, $this->weeks->weekStartDow($settings));

        return $this->queue($store, 'clear_week', $target->toDateString(), [], 'replace', $userId);
    }

    /**
     * Build the item list and hand it to the queue.
     *
     * In `replace` mode the deletes are sequenced FIRST and as their own items,
     * so a failure part-way through is visible rather than silently producing a
     * doubled week.
     */
    private function queue(
        Store $store,
        string $type,
        string $targetWeek,
        array $createPayloads,
        string $mode,
        ?int $userId,
        array $extra = [],
    ): ScheduleBulkOperation {
        $target = \Carbon\CarbonImmutable::parse($targetWeek);

        $deletes = [];

        if ($mode === 'replace' || $type === 'clear_week') {
            $existing = $this->shifts->assignmentsForRange(
                $store,
                $target->toDateString(),
                $target->addDays(6)->toDateString()
            );

            foreach ($existing as $assignment) {
                if ($assignment->shift !== null) {
                    $deletes[] = ['shift_id' => (int) $assignment->shift_id];
                }
            }

            $deletes = collect($deletes)->unique('shift_id')->values()->all();
        }

        return DB::transaction(function () use ($store, $type, $targetWeek, $createPayloads, $deletes, $mode, $userId, $extra) {
            $operation = ScheduleBulkOperation::query()->create([
                'store_id' => $store->id,
                'type' => $type,
                'status' => ScheduleBulkOperation::STATUS_QUEUED,
                'week_start_date' => $targetWeek,
                'source_week_start_date' => $extra['source_week_start_date'] ?? null,
                'schedule_template_id' => $extra['schedule_template_id'] ?? null,
                'total_items' => count($deletes) + count($createPayloads),
                'params' => ['mode' => $mode],
                'requested_by_user_id' => $userId,
            ]);

            $sequence = 0;

            foreach ($deletes as $delete) {
                $operation->items()->create([
                    'sequence' => $sequence++,
                    'action' => 'delete',
                    'shift_id' => $delete['shift_id'],
                    'payload' => $delete,
                ]);
            }

            foreach ($createPayloads as $payload) {
                $operation->items()->create([
                    'sequence' => $sequence++,
                    'action' => 'create',
                    'employee_id' => $payload['employee_id'],
                    'payload' => $payload,
                ]);
            }

            ProcessBulkOperationJob::dispatch($operation->id);

            return $operation;
        });
    }

    public function present(ScheduleBulkOperation $operation, bool $withItems = true): array
    {
        $data = [
            'id' => (string) $operation->id,
            'type' => $operation->type,
            'status' => $operation->status,
            'total' => (int) $operation->total_items,
            'succeeded' => (int) $operation->succeeded_items,
            'failed' => (int) $operation->failed_items,
            'progress_percent' => $operation->progressPercent(),
            'week_start_date' => $operation->week_start_date?->toDateString(),
            'started_at' => $operation->started_at?->toIso8601String(),
            'finished_at' => $operation->finished_at?->toIso8601String(),
            'error' => $operation->error,
        ];

        if ($withItems) {
            // Only the failures matter to the UI — a successful copy-week would
            // otherwise ship 70 rows nobody reads.
            $data['items'] = $operation->items()
                ->where('status', 'failed')
                ->with('employee')
                ->orderBy('sequence')
                ->get()
                ->map(fn ($item) => [
                    'sequence' => (int) $item->sequence,
                    'action' => $item->action,
                    'status' => $item->status,
                    'employee_id' => $item->employee_id === null ? null : (string) $item->employee_id,
                    'employee_name' => $item->employee === null
                        ? null
                        : trim("{$item->employee->first_name} {$item->employee->last_name}"),
                    'shift_date' => $item->payload['shift_date'] ?? null,
                    'start_time' => $item->payload['start_time'] ?? null,
                    'end_time' => $item->payload['end_time'] ?? null,
                    'error_code' => $item->error_code,
                    'error_message' => $item->error_message,
                ])
                ->values()
                ->all();
        }

        return $data;
    }
}
