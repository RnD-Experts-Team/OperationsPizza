<?php

namespace App\Services\Scheduling;

use App\Models\ActualShift;
use App\Models\ShiftAssignment;
use App\Models\Store;

/**
 * The "what actually happened" review layer.
 *
 * Owned entirely here and NEVER pushed to Humanity — it is a manager's record,
 * not a schedule. Keeping it one-way is what leaves exactly one write-through
 * path in the system.
 */
class ActualShiftService
{
    public function __construct(
        private readonly ShiftTimeResolver $times,
        private readonly StoreTimezoneResolver $timezones,
    ) {
    }

    /** One click: "they worked exactly what was planned". */
    public function confirmPlanned(Store $store, ShiftAssignment $assignment, ?int $userId = null): ActualShift
    {
        $shift = $assignment->shift;

        return $this->upsert($store, [
            'employee_id' => (int) $assignment->employee_id,
            'shift_assignment_id' => (int) $assignment->id,
            'shift_date' => $shift->shift_date->toDateString(),
            'start_time' => substr((string) $shift->start_time, 0, 5),
            'end_time' => substr((string) $shift->end_time, 0, 5),
            'label' => $shift->label,
            'shift_type' => $shift->shift_type,
            'status' => ActualShift::STATUS_CONFIRMED,
        ], $userId);
    }

    public function markAbsent(Store $store, ShiftAssignment $assignment, ?string $note = null, ?int $userId = null): ActualShift
    {
        $shift = $assignment->shift;

        return $this->upsert($store, [
            'employee_id' => (int) $assignment->employee_id,
            'shift_assignment_id' => (int) $assignment->id,
            'shift_date' => $shift->shift_date->toDateString(),
            'start_time' => substr((string) $shift->start_time, 0, 5),
            'end_time' => substr((string) $shift->end_time, 0, 5),
            'label' => $shift->label,
            'shift_type' => $shift->shift_type,
            'status' => ActualShift::STATUS_ABSENT,
            'note' => $note,
        ], $userId);
    }

    /**
     * Create or amend an actual entry. The status is DERIVED, not accepted from
     * the client: an entry matching its planned shift is `confirmed`, one that
     * differs is `modified`, and one with no planned counterpart is `added`.
     * Letting the caller assert it would let the two drift.
     */
    public function upsert(Store $store, array $data, ?int $userId = null): ActualShift
    {
        $timezone = $this->timezones->for($store);

        $time = $this->times->resolve(
            $data['shift_date'],
            $data['start_time'],
            $data['end_time'],
            $timezone
        );

        $assignment = isset($data['shift_assignment_id'])
            ? ShiftAssignment::query()->with('shift')->find($data['shift_assignment_id'])
            : null;

        $status = $data['status'] ?? $this->deriveStatus($assignment, $data);

        $attributes = $time->toAttributes() + [
            'store_id' => $store->id,
            'employee_id' => $data['employee_id'],
            'shift_assignment_id' => $assignment?->id,
            'label' => $data['label'] ?? null,
            'shift_type' => $data['shift_type'] ?? 'custom',
            'status' => $status,
            'note' => $data['note'] ?? null,
            'source' => $data['source'] ?? 'manual',
            'reviewed_by_user_id' => $userId,
            'reviewed_at' => now(),
        ];

        if ($assignment !== null) {
            // One actual per planned assignment — re-reviewing amends rather
            // than stacking duplicates.
            return ActualShift::query()->updateOrCreate(
                ['shift_assignment_id' => $assignment->id],
                $attributes
            );
        }

        return ActualShift::query()->create($attributes);
    }

    private function deriveStatus(?ShiftAssignment $assignment, array $data): string
    {
        if ($assignment?->shift === null) {
            return ActualShift::STATUS_ADDED;
        }

        $shift = $assignment->shift;

        $sameTimes = substr((string) $shift->start_time, 0, 5) === substr((string) $data['start_time'], 0, 5)
            && substr((string) $shift->end_time, 0, 5) === substr((string) $data['end_time'], 0, 5);

        return $sameTimes ? ActualShift::STATUS_CONFIRMED : ActualShift::STATUS_MODIFIED;
    }

    public function present(ActualShift $actual, ?int $dayIndex = null): array
    {
        return [
            'id' => (string) $actual->id,
            'employee_id' => (string) $actual->employee_id,
            'planned_shift_id' => $actual->shift_assignment_id === null ? null : (string) $actual->shift_assignment_id,
            'shift_date' => $actual->shift_date?->toDateString(),
            'day_index' => $dayIndex,
            'start_time' => substr((string) $actual->start_time, 0, 5),
            'end_time' => substr((string) $actual->end_time, 0, 5),
            'duration_minutes' => (int) $actual->duration_minutes,
            'label' => $actual->label,
            'type' => $actual->shift_type,
            'status' => $actual->status,
            'note' => $actual->note,
            'source' => $actual->source,
        ];
    }
}
