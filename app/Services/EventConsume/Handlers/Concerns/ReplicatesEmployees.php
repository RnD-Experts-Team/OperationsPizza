<?php

namespace App\Services\EventConsume\Handlers\Concerns;

use App\Models\Employee;
use App\Models\EmployeeAvailabilityDay;
use App\Models\EmployeeContact;
use App\Models\EmployeeExternalId;
use App\Models\EmployeePayHistory;
use App\Models\EmployeeStatusHistory;
use App\Models\EmployeeStore;
use App\Models\EmployeeSyncRequest;
use App\Models\Position;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

/**
 * Shared parsing for HiringPizza employee events.
 *
 * The two events have DIFFERENT shapes, and this asymmetry is the single most
 * important contract detail in the whole integration:
 *
 *   hiring.v1.employee.created → full snapshot at data.employee
 *   hiring.v1.employee.updated → deltas only at data.changed_fields.<f>.{from,to}
 *
 * Hiring's own sync* methods delete-and-reinsert every child collection, so a
 * collection delta ships the WHOLE array rather than a per-row patch. Every
 * collection here is therefore replaced wholesale, never merged.
 */
trait ReplicatesEmployees
{
    protected function extractEmployeePayload(array $event): array
    {
        foreach (['data.employee', 'employee', 'payload.employee'] as $path) {
            $value = data_get($event, $path);
            if (is_array($value)) {
                return $value;
            }
        }

        return [];
    }

    protected function resolveEmployeeId(array $event, array $payload): int
    {
        $id = $this->asInt(data_get($payload, 'id'));
        if ($id > 0) {
            return $id;
        }

        return $this->asInt(
            data_get($event, 'data.employee_id') ?? data_get($event, 'employee_id')
        );
    }

    protected function changedFields(array $event): array
    {
        $changed = data_get($event, 'data.changed_fields');

        return is_array($changed) ? $changed : [];
    }

    /**
     * A collection from either shape, or null when this event says nothing
     * about it — null means "leave what we have alone", which is different
     * from an empty array meaning "hiring now has none".
     */
    protected function resolveCollection(array $event, array $payload, string $field): ?array
    {
        $value = data_get($payload, $field);
        if (is_array($value)) {
            return $value;
        }

        $value = data_get($event, "data.changed_fields.{$field}.to");
        if (is_array($value)) {
            return $value;
        }

        return null;
    }

    protected function resolveScalar(array $event, array $payload, string $field): ?string
    {
        $changed = $this->changedFields($event);

        if (array_key_exists($field, $changed)) {
            $value = $changed[$field];

            return is_array($value) && array_key_exists('to', $value)
                ? $this->stringOrNull($value['to'])
                : $this->stringOrNull($value);
        }

        return $this->stringOrNull(data_get($payload, $field));
    }

    /**
     * Guard against JetStream replaying an older update after a newer one.
     * Returns false when this event predates what we already applied.
     */
    protected function isNewerThanApplied(Employee $employee, array $event): bool
    {
        $eventTime = $this->stringOrNull(data_get($event, 'time'));

        if ($eventTime === null || $employee->hiring_event_at === null) {
            return true;
        }

        $timestamp = strtotime($eventTime);

        return $timestamp === false || $timestamp >= $employee->hiring_event_at->getTimestamp();
    }

    protected function eventTime(array $event): ?string
    {
        $time = $this->stringOrNull(data_get($event, 'time'));
        if ($time === null) {
            return null;
        }

        $timestamp = strtotime($time);

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    // ---------------------------------------------------------------- writes

    protected function replaceContacts(int $employeeId, array $contacts): void
    {
        EmployeeContact::query()->where('employee_id', $employeeId)->delete();

        foreach ($contacts as $contact) {
            if (!is_array($contact)) {
                continue;
            }

            $value = $this->stringOrNull(data_get($contact, 'contact_value'))
                ?? $this->stringOrNull(data_get($contact, 'value'));

            if ($value === null) {
                continue;
            }

            EmployeeContact::query()->create([
                'employee_id' => $employeeId,
                'type' => $this->stringOrNull(data_get($contact, 'contact_type'))
                    ?? $this->stringOrNull(data_get($contact, 'type'))
                    ?? 'email',
                'name' => $this->stringOrNull(data_get($contact, 'contact_name')),
                'value' => $value,
                'is_primary' => (bool) data_get($contact, 'is_primary', false),
            ]);
        }
    }

    /**
     * External ids are upserted rather than replaced, and the Humanity ID is
     * lifted onto the employee row. Losing that link would make the employee
     * unschedulable and trigger a spurious re-sync request.
     */
    protected function syncExternalIds(Employee $employee, array $ids): void
    {
        $seen = [];

        foreach ($ids as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $type = $this->stringOrNull(data_get($entry, 'id_type.label'))
                ?? $this->stringOrNull(data_get($entry, 'id_type'));
            $value = $this->stringOrNull(data_get($entry, 'id_value'))
                ?? $this->stringOrNull(data_get($entry, 'value'));

            if ($type === null || $value === null) {
                continue;
            }

            $seen[] = $type;

            EmployeeExternalId::query()->updateOrCreate(
                ['employee_id' => $employee->id, 'id_type' => $type],
                ['value' => $value]
            );
        }

        EmployeeExternalId::query()
            ->where('employee_id', $employee->id)
            ->when($seen !== [], fn ($q) => $q->whereNotIn('id_type', $seen))
            ->delete();

        $this->liftHumanityId($employee);
    }

    protected function liftHumanityId(Employee $employee): void
    {
        $humanityId = EmployeeExternalId::query()
            ->where('employee_id', $employee->id)
            ->where('id_type', EmployeeExternalId::HUMANITY)
            ->value('value');

        $humanityId = $this->stringOrNull($humanityId);

        if ($humanityId === $employee->humanity_employee_id) {
            return;
        }

        $employee->forceFill([
            'humanity_employee_id' => $humanityId,
            'humanity_synced_at' => $humanityId === null ? null : now(),
        ])->save();

        // The link just arrived — this is what closes the loop opened by
        // operations.v1.employee.humanity_sync_requested.
        if ($humanityId !== null) {
            EmployeeSyncRequest::query()
                ->where('employee_id', $employee->id)
                ->where('status', '!=', 'fulfilled')
                ->update(['status' => 'fulfilled', 'fulfilled_at' => now(), 'last_error' => null]);
        }
    }

    protected function replaceStatusHistories(int $employeeId, array $histories): void
    {
        EmployeeStatusHistory::query()->where('employee_id', $employeeId)->delete();

        foreach ($histories as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $status = $this->stringOrNull(data_get($entry, 'status'));
            $effective = $this->stringOrNull(data_get($entry, 'effective_date'));

            if ($status === null || $effective === null) {
                continue;
            }

            EmployeeStatusHistory::query()->create([
                'employee_id' => $employeeId,
                'status' => strtolower($status),
                'effective_date' => $effective,
                'store_number' => $this->stringOrNull(data_get($entry, 'store.store_number')),
            ]);
        }
    }

    protected function replacePayHistories(int $employeeId, array $histories): void
    {
        EmployeePayHistory::query()->where('employee_id', $employeeId)->delete();

        foreach ($histories as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $effective = $this->stringOrNull(data_get($entry, 'effective_date'));
            $basePay = data_get($entry, 'base_pay');

            if ($effective === null || $basePay === null) {
                continue;
            }

            EmployeePayHistory::query()->create([
                'employee_id' => $employeeId,
                'base_pay' => (float) $basePay,
                'performance_pay' => data_get($entry, 'performance_pay') === null
                    ? null
                    : (float) data_get($entry, 'performance_pay'),
                'effective_date' => $effective,
            ]);
        }
    }

    protected function replaceAvailability(int $employeeId, array $days): void
    {
        // Times cascade-delete with their day.
        EmployeeAvailabilityDay::query()->where('employee_id', $employeeId)->delete();

        foreach ($days as $day) {
            if (!is_array($day)) {
                continue;
            }

            $dayOfWeek = $this->stringOrNull(data_get($day, 'day_of_week'));
            if ($dayOfWeek === null) {
                continue;
            }

            $row = EmployeeAvailabilityDay::query()->create([
                'employee_id' => $employeeId,
                'day_of_week' => strtolower($dayOfWeek),
                'shift_type' => $this->stringOrNull(data_get($day, 'shift_type')) ?? 'OP',
            ]);

            foreach ((array) data_get($day, 'times', []) as $time) {
                if (!is_array($time)) {
                    continue;
                }

                $from = $this->stringOrNull(data_get($time, 'available_from'));
                $to = $this->stringOrNull(data_get($time, 'available_to'));

                if ($from === null || $to === null) {
                    continue;
                }

                $row->times()->create(['available_from' => $from, 'available_to' => $to]);
            }
        }
    }

    protected function replacePositions(int $employeeId, array $positions): void
    {
        $positionIds = [];

        foreach ($positions as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $positionId = $this->asInt(data_get($entry, 'position.id') ?? data_get($entry, 'position_id'));
            if ($positionId <= 0) {
                continue;
            }

            // The reference row travels inside the employee snapshot, so
            // replicate it here rather than needing a separate catalog event.
            Position::query()->updateOrCreate(
                ['id' => $positionId],
                [
                    'label' => $this->stringOrNull(data_get($entry, 'position.label')) ?? "Position {$positionId}",
                    'description' => $this->stringOrNull(data_get($entry, 'position.description')),
                ]
            );

            $positionIds[$positionId] = [
                // Hiring arrays are newest-first, so the first one wins.
                'is_primary' => $positionIds === [],
                'effective_date' => $this->stringOrNull(data_get($entry, 'effective_date')),
            ];
        }

        DB::table('employee_position')->where('employee_id', $employeeId)->delete();

        foreach ($positionIds as $positionId => $pivot) {
            DB::table('employee_position')->insert([
                'employee_id' => $employeeId,
                'position_id' => $positionId,
                'is_primary' => $pivot['is_primary'],
                'effective_date' => $pivot['effective_date'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Store membership, with each store's status matched from the status
     * histories that apply to it. Returns the rows so the caller can derive
     * `active` without re-reading.
     *
     * @return array<int, array{store_number:string,status:?string,active:bool,effective_date:?string}>
     */
    protected function buildMembershipRows(array $stores, array $statusHistories): array
    {
        $overallStatus = $this->latestStatus($statusHistories);

        $rows = [];

        foreach ($stores as $store) {
            if (!is_array($store)) {
                continue;
            }

            $storeNumber = $this->stringOrNull(data_get($store, 'store.store_number'))
                ?? $this->stringOrNull(data_get($store, 'store_number'));

            if ($storeNumber === null || isset($rows[$storeNumber])) {
                continue;
            }

            $matching = array_values(array_filter($statusHistories, function ($entry) use ($storeNumber) {
                return is_array($entry)
                    && $this->stringOrNull(data_get($entry, 'store.store_number')) === $storeNumber;
            }));

            $status = $this->latestStatus($matching) ?? $overallStatus;

            $rows[$storeNumber] = [
                'store_number' => $storeNumber,
                'status' => $status,
                'active' => $status !== null && in_array($status, Employee::ACTIVE_STATUSES, true),
                'effective_date' => $this->stringOrNull(data_get($store, 'effective_date')),
            ];
        }

        return array_values($rows);
    }

    protected function replaceMemberships(int $employeeId, array $memberships): void
    {
        EmployeeStore::query()->where('employee_id', $employeeId)->delete();

        foreach ($memberships as $membership) {
            EmployeeStore::query()->create([
                'employee_id' => $employeeId,
                'store_number' => $membership['store_number'],
                // Null when pizzasys' store hasn't replicated yet; backfilled
                // by StoreCreatedHandler order or the next employee event.
                'store_id' => Store::query()->where('store_number', $membership['store_number'])->value('id'),
                'status' => $membership['status'],
                'active' => $membership['active'],
                'effective_date' => $membership['effective_date'],
            ]);
        }
    }

    protected function anyActive(array $memberships): bool
    {
        foreach ($memberships as $membership) {
            if (!empty($membership['active'])) {
                return true;
            }
        }

        return false;
    }

    /** Latest status by effective_date, tiebreaking on id — hiring's own rule. */
    protected function latestStatus(array $histories): ?string
    {
        $latest = null;
        $latestKey = null;

        foreach ($histories as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $date = $this->stringOrNull(data_get($entry, 'effective_date'));
            $timestamp = $date === null ? 0 : (strtotime($date) ?: 0);
            $key = [$timestamp, $this->asInt(data_get($entry, 'id'))];

            if ($latestKey === null || $key > $latestKey) {
                $latestKey = $key;
                $latest = $entry;
            }
        }

        $status = $this->stringOrNull(data_get($latest, 'status'));

        return $status === null ? null : strtolower($status);
    }

    protected function latestStatusEffectiveDate(array $histories): ?string
    {
        $latest = null;
        $latestKey = null;

        foreach ($histories as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $date = $this->stringOrNull(data_get($entry, 'effective_date'));
            $timestamp = $date === null ? 0 : (strtotime($date) ?: 0);
            $key = [$timestamp, $this->asInt(data_get($entry, 'id'))];

            if ($latestKey === null || $key > $latestKey) {
                $latestKey = $key;
                $latest = $entry;
            }
        }

        return $this->stringOrNull(data_get($latest, 'effective_date'));
    }

    protected function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return null;
    }

    protected function asInt(mixed $v): int
    {
        if (is_int($v)) {
            return $v;
        }

        if (is_string($v) && ctype_digit($v)) {
            return (int) $v;
        }

        if (is_numeric($v)) {
            return (int) $v;
        }

        return 0;
    }
}
