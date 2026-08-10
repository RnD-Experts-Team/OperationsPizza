<?php

namespace App\Services\Scheduling;

use App\Jobs\PublishOutboxEventJob;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Store;
use App\Services\Humanity\Dto\HumanityShiftPayload;
use App\Services\Humanity\Dto\HumanityShiftResult;
use App\Services\Humanity\Exceptions\HumanityException;
use App\Services\Humanity\HumanityClientInterface;
use App\Services\Humanity\HumanityPositionResolver;
use App\Services\Humanity\HumanitySyncLogger;
use App\Services\OperationsEvents\OperationsEventFactory;
use App\Services\OperationsEvents\OperationsOutboxService;
use App\Services\Scheduling\Dto\ResolvedShiftTime;
use App\Services\Scheduling\Exceptions\EmployeeNotSyncedException;
use App\Services\Scheduling\Exceptions\SchedulingException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The ONLY place a planned shift is written.
 *
 * Humanity is the source of truth, so the ordering is fixed:
 *
 *   validate -> resolve mapping -> call Humanity -> persist the mirror
 *
 * The Humanity call happens deliberately OUTSIDE any DB transaction. Holding a
 * transaction open across a network round trip is how connection pools die, and
 * if persistence fails after Humanity accepted the write the reconciler will
 * pick the shift up and create the mirror row — which is exactly why it must be
 * able to create rows, not only update them.
 */
class ShiftWriteService
{
    public function __construct(
        private readonly HumanityClientInterface $humanity,
        private readonly HumanityPositionResolver $positions,
        private readonly HumanitySyncLogger $syncLog,
        private readonly ShiftTimeResolver $times,
        private readonly StoreTimezoneResolver $timezones,
        private readonly WeekResolver $weeks,
        private readonly ConflictDetector $conflicts,
        private readonly AvailabilityProjector $availability,
        private readonly EmployeeSyncRequestService $syncRequests,
        private readonly ShiftFingerprint $fingerprint,
        private readonly OperationsEventFactory $events,
        private readonly OperationsOutboxService $outbox,
    ) {
    }

    /**
     * @param  array{employee_id:int, shift_date:string, start_time:string, end_time:string, label?:string,
     *               shift_type?:string, note?:string, position_id?:int, slots?:int, force?:bool}  $data
     */
    public function create(Store $store, array $data, ?Request $request = null): Shift
    {
        $settings = $store->settings();
        $timezone = $this->timezones->for($store);

        $employee = $this->resolveEmployee($store, (int) $data['employee_id']);

        $time = $this->times->resolve(
            $data['shift_date'],
            $data['start_time'],
            $data['end_time'],
            $timezone
        );

        $this->guard($store, $employee, $time, $settings, (bool) ($data['force'] ?? false));

        $locationId = $this->positions->locationId($store);
        $positionId = $this->positions->positionId($store, $employee, $data['position_id'] ?? null);

        $humanityEmployeeId = $this->requireHumanityLink($employee, $store, $request);

        $payload = new HumanityShiftPayload(
            locationId: $locationId,
            positionId: $positionId,
            startsLocal: $time->startsLocal,
            endsLocal: $time->endsLocal,
            employeeIds: [$humanityEmployeeId],
            title: $data['label'] ?? null,
            note: $data['note'] ?? null,
            slots: (int) ($data['slots'] ?? 1),
        );

        $log = $this->syncLog->begin(
            entityType: 'shift',
            operation: 'create',
            storeId: (int) $store->id,
            requestPayload: $data,
            correlationId: $this->correlationId($request),
        );

        $startedAt = microtime(true);

        try {
            $result = $this->humanity->createShift($payload);
        } catch (\Throwable $e) {
            $this->syncLog->failed($log, $e, $this->elapsed($startedAt));

            throw $e;
        }

        $this->syncLog->succeeded($log, $result->shiftId, $result->raw, $this->elapsed($startedAt));

        return DB::transaction(function () use ($store, $employee, $time, $data, $locationId, $positionId, $result, $humanityEmployeeId, $request) {
            $shift = Shift::query()->create(array_merge($time->toAttributes(), [
                'store_id' => $store->id,
                'humanity_location_id' => $locationId,
                'humanity_position_id' => $positionId,
                'humanity_shift_id' => $result->shiftId,
                'label' => $data['label'] ?? null,
                'shift_type' => $data['shift_type'] ?? 'custom',
                'note' => $data['note'] ?? null,
                'slots' => (int) ($data['slots'] ?? 1),
                'is_published' => false,
                'origin' => Shift::ORIGIN_OPERATIONS,
                'recurring_group_id' => $data['recurring_group_id'] ?? null,
                'created_by_user_id' => $request?->user()?->id,
            ]));

            $shift->assignments()->create([
                'employee_id' => $employee->id,
                'humanity_employee_id' => $humanityEmployeeId,
                'status' => 'assigned',
            ]);

            $shift->update([
                'humanity_hash' => $this->fingerprint->forLocalShift($shift->fresh(), [$humanityEmployeeId]),
            ]);

            $this->recordEvent('operations.v1.shift.created', [
                'shift_id' => $shift->id,
                'humanity_shift_id' => $result->shiftId,
                'store_number' => $store->store_number,
                'employee_id' => $employee->id,
                'shift_date' => $time->shiftDate,
            ], $request);

            return $shift->load('assignments');
        });
    }

    /**
     * Update times/label/note, and optionally move the shift to a different
     * employee (which in Humanity is an unassign + assign, not a field edit).
     */
    public function update(Store $store, Shift $shift, array $data, ?Request $request = null): Shift
    {
        $settings = $store->settings();
        $timezone = $this->timezones->for($store);

        $assignment = $shift->assignments()->first();

        if ($assignment === null) {
            throw new SchedulingException('This shift has no assignment to update.', 'SHIFT_UNASSIGNED', 422);
        }

        $employee = array_key_exists('employee_id', $data) && (int) $data['employee_id'] !== (int) $assignment->employee_id
            ? $this->resolveEmployee($store, (int) $data['employee_id'])
            : $this->resolveEmployee($store, (int) $assignment->employee_id);

        $time = $this->times->resolve(
            $data['shift_date'] ?? $shift->shift_date->toDateString(),
            $data['start_time'] ?? $shift->start_time,
            $data['end_time'] ?? $shift->end_time,
            $timezone
        );

        $this->guard($store, $employee, $time, $settings, (bool) ($data['force'] ?? false), $shift->id);

        $locationId = $shift->humanity_location_id ?: $this->positions->locationId($store);
        $positionId = $data['position_id'] ?? null
            ? $this->positions->positionId($store, $employee, (int) $data['position_id'])
            : ($shift->humanity_position_id ?: $this->positions->positionId($store, $employee));

        $humanityEmployeeId = $this->requireHumanityLink($employee, $store, $request);

        $payload = new HumanityShiftPayload(
            locationId: $locationId,
            positionId: $positionId,
            startsLocal: $time->startsLocal,
            endsLocal: $time->endsLocal,
            employeeIds: [$humanityEmployeeId],
            title: $data['label'] ?? $shift->label,
            note: $data['note'] ?? $shift->note,
            slots: (int) ($data['slots'] ?? $shift->slots),
        );

        $log = $this->syncLog->begin(
            entityType: 'shift',
            operation: 'update',
            entityId: (int) $shift->id,
            storeId: (int) $store->id,
            humanityId: $shift->humanity_shift_id,
            requestPayload: $data,
            correlationId: $this->correlationId($request),
        );

        $startedAt = microtime(true);

        try {
            $result = $this->humanity->updateShift((string) $shift->humanity_shift_id, $payload);

            // Reassignment is a separate call: updateShift does not touch
            // staffing in Humanity, only the shift's own fields.
            if ((int) $assignment->employee_id !== (int) $employee->id) {
                if ($assignment->humanity_employee_id) {
                    $this->humanity->unassignEmployees((string) $shift->humanity_shift_id, [$assignment->humanity_employee_id]);
                }

                $result = $this->humanity->assignEmployees(
                    (string) $shift->humanity_shift_id,
                    [$humanityEmployeeId],
                    (bool) ($data['force'] ?? false)
                );
            }
        } catch (\Throwable $e) {
            $this->syncLog->failed($log, $e, $this->elapsed($startedAt));

            throw $e;
        }

        $this->syncLog->succeeded($log, $result->shiftId, $result->raw, $this->elapsed($startedAt));

        return DB::transaction(function () use ($shift, $assignment, $employee, $time, $data, $positionId, $humanityEmployeeId, $store, $request) {
            $shift->update(array_merge($time->toAttributes(), [
                'humanity_position_id' => $positionId,
                'label' => $data['label'] ?? $shift->label,
                'shift_type' => $data['shift_type'] ?? $shift->shift_type,
                'note' => array_key_exists('note', $data) ? $data['note'] : $shift->note,
                'slots' => (int) ($data['slots'] ?? $shift->slots),
            ]));

            $assignment->update([
                'employee_id' => $employee->id,
                'humanity_employee_id' => $humanityEmployeeId,
            ]);

            $shift->update([
                'humanity_hash' => $this->fingerprint->forLocalShift($shift->fresh(), [$humanityEmployeeId]),
            ]);

            $this->recordEvent('operations.v1.shift.updated', [
                'shift_id' => $shift->id,
                'humanity_shift_id' => $shift->humanity_shift_id,
                'store_number' => $store->store_number,
                'employee_id' => $employee->id,
                'shift_date' => $time->shiftDate,
            ], $request);

            return $shift->fresh(['assignments']);
        });
    }

    public function delete(Store $store, Shift $shift, ?Request $request = null): void
    {
        $log = $this->syncLog->begin(
            entityType: 'shift',
            operation: 'delete',
            entityId: (int) $shift->id,
            storeId: (int) $store->id,
            humanityId: $shift->humanity_shift_id,
            correlationId: $this->correlationId($request),
        );

        $startedAt = microtime(true);

        try {
            if ($shift->humanity_shift_id) {
                $this->humanity->deleteShift((string) $shift->humanity_shift_id);
            }
        } catch (\Throwable $e) {
            $this->syncLog->failed($log, $e, $this->elapsed($startedAt));

            // Never soft-delete locally when the remote delete failed: that
            // produces a shift invisible to managers but still live for the
            // employee, which is the worst divergence available.
            throw $e;
        }

        $this->syncLog->succeeded($log, $shift->humanity_shift_id, [], $this->elapsed($startedAt));

        DB::transaction(function () use ($shift, $store, $request) {
            $shift->assignments()->delete();
            $shift->delete();

            $this->recordEvent('operations.v1.shift.deleted', [
                'shift_id' => $shift->id,
                'humanity_shift_id' => $shift->humanity_shift_id,
                'store_number' => $store->store_number,
            ], $request);
        });
    }

    // ------------------------------------------------------------------ guards

    private function guard(
        Store $store,
        Employee $employee,
        ResolvedShiftTime $time,
        $settings,
        bool $force,
        ?int $ignoreShiftId = null,
    ): void {
        if ($force) {
            return;
        }

        $conflicts = $this->conflicts->conflictsFor(
            (int) $employee->id,
            $time->startsAtUtc,
            $time->endsAtUtc,
            $ignoreShiftId
        );

        if ($conflicts->isNotEmpty()) {
            throw SchedulingException::conflict($this->conflicts->describe($conflicts));
        }

        // The projector works a week at a time, so ask for the week that
        // actually contains this shift — using the store's configured start
        // day, not PHP's default Monday.
        $weekStart = $this->weeks->weekStartFor(
            CarbonImmutable::parse($time->shiftDate),
            $this->weeks->weekStartDow($settings)
        );

        $rules = $this->availability->forWeek(
            $store,
            collect([$employee->load('availabilityDays.times')]),
            $weekStart,
            $settings
        );

        $blocking = $this->availability->blocking(
            $rules,
            (int) $employee->id,
            $time->shiftDate,
            substr($time->startTime, 0, 5),
            substr($time->endTime, 0, 5)
        );

        if ($blocking !== []) {
            throw SchedulingException::unavailable($blocking);
        }
    }

    private function resolveEmployee(Store $store, int $employeeId): Employee
    {
        $employee = Employee::query()
            ->with(['positions', 'availabilityDays.times'])
            ->assignedToStore((string) $store->store_number)
            ->find($employeeId);

        if ($employee === null) {
            throw new SchedulingException(
                "Employee {$employeeId} is not assigned to store {$store->store_number}.",
                'EMPLOYEE_NOT_IN_STORE',
                404,
                ['employee_id' => $employeeId]
            );
        }

        return $employee;
    }

    /**
     * The unsynced-employee fork. Nothing is written anywhere: we ask
     * HiringPizza over NATS and hand the UI a resumable error so the manager's
     * typed shift survives the wait.
     */
    private function requireHumanityLink(Employee $employee, Store $store, ?Request $request): string
    {
        if ($employee->isLinkedToHumanity()) {
            return (string) $employee->humanity_employee_id;
        }

        $syncRequest = $this->syncRequests->request(
            $employee,
            (string) $store->store_number,
            $request?->user()?->id,
            $request
        );

        Log::info('Scheduling hit an employee with no Humanity link; sync requested', [
            'employee_id' => $employee->id,
            'store_number' => $store->store_number,
            'attempts' => $syncRequest->attempts,
        ]);

        throw new EmployeeNotSyncedException($employee, $syncRequest, (string) $store->store_number);
    }

    private function recordEvent(string $subject, array $data, ?Request $request): void
    {
        $envelope = $this->events->make($subject, $data, $request);
        $row = $this->outbox->record($subject, $envelope);

        PublishOutboxEventJob::dispatch($row->id);
    }

    private function correlationId(?Request $request): string
    {
        return $request?->headers->get('X-Correlation-Id') ?? (string) Str::ulid();
    }

    private function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
