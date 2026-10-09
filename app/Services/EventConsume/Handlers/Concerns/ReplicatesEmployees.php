<?php

namespace App\Services\EventConsume\Handlers\Concerns;

use App\Jobs\SyncEmployeeToHumanityJob;
use App\Models\Employee;
use App\Models\EmployeeAvailabilityDay;
use App\Models\EmployeeStore;
use App\Models\EmployeeSyncRequest;
use App\Models\Store;

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
 *
 * The snapshot still carries HiringPizza's full HR record — pay history,
 * contacts, demographics, payroll ids. We deliberately read only the few parts
 * a scheduling service needs and let the rest fall on the floor.
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

    /**
     * Lift the two external links we care about straight off the payload.
     *
     * HiringPizza's ids[] also carries payroll-system ids (Altametrics,
     * Paychecks) that a scheduling service has no use for, so nothing is
     * stored as rows — only the Humanity and TCP links, as columns.
     *
     * An ids[] array that omits a type nulls that column, matching the old
     * delete-then-read behaviour.
     */
    protected function syncExternalIds(Employee $employee, array $ids): void
    {
        $byType = [];

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

            // First wins, matching the old unique (employee_id, id_type).
            $byType[$type] ??= $value;
        }

        $this->liftHumanityId($employee, $byType[Employee::HUMANITY_ID_LABEL] ?? null);
        $this->liftTcpId($employee, $byType[Employee::TCP_ID_LABEL] ?? null);
    }

    /**
     * TCP Manager+ is the clocking system, so this link is what lets a punch or
     * a worked segment be attributed to one of our employees.
     */
    protected function liftTcpId(Employee $employee, ?string $tcpId): void
    {
        if ($tcpId === $employee->tcp_employee_id) {
            return;
        }

        $employee->forceFill([
            'tcp_employee_id' => $tcpId,
            'tcp_synced_at' => $tcpId === null ? null : now(),
        ])->save();

        // The link just arrived — this is what closes the loop opened by
        // operations.v1.employee.tcp_sync_requested.
        if ($tcpId !== null) {
            EmployeeSyncRequest::query()
                ->where('employee_id', $employee->id)
                ->where('status', '!=', 'fulfilled')
                ->update(['status' => 'fulfilled', 'fulfilled_at' => now(), 'last_error' => null]);

            // Go and fetch the Humanity id rather than waiting for someone to
            // try to schedule them (or for the nightly backstop). Delayed
            // because TCP's own connector has to carry the employee across
            // first, and it runs every ~5 minutes; afterCommit so a rolled-back
            // event replay doesn't queue work for an employee we didn't keep.
            SyncEmployeeToHumanityJob::dispatch((int) $employee->id)
                ->delay(now()->addMinutes((int) config('humanity.employee_link_delay_minutes', 20)))
                ->afterCommit();
        }
    }

    protected function liftHumanityId(Employee $employee, ?string $humanityId): void
    {
        if ($humanityId === $employee->humanity_employee_id) {
            return;
        }

        $employee->forceFill([
            'humanity_employee_id' => $humanityId,
            'humanity_synced_at' => $humanityId === null ? null : now(),
        ])->save();
    }

    /**
     * The wage in effect today, collapsed out of hiring's pay history.
     *
     * We keep a single rate rather than the history: labor costing is the only
     * consumer, and pay history is HR data. The rate is therefore FROZEN AT
     * INGEST — a future-dated raise does not start applying on its effective
     * date, it applies when the next employee event lands. Nothing in
     * scheduling reads a rate for a past date, so that trade is deliberate.
     *
     * Do not trust the array's order: hiring sorts it newest-first today, but
     * this is cheap to make independent of that.
     */
    protected function currentHourlyRate(array $payHistories): ?string
    {
        $today = now()->toDateString();

        $effectiveRate = $newestRate = null;
        $effectiveKey = $newestKey = null;

        foreach ($payHistories as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $basePay = data_get($entry, 'base_pay');
            $date = $this->stringOrNull(data_get($entry, 'effective_date'));

            if ($date === null || $basePay === null || !is_numeric($basePay)) {
                continue;
            }

            $timestamp = strtotime($date) ?: 0;
            $key = [$timestamp, $this->asInt(data_get($entry, 'id'))];

            if ($newestKey === null || $key > $newestKey) {
                $newestKey = $key;
                $newestRate = $basePay;
            }

            if (date('Y-m-d', $timestamp) <= $today && ($effectiveKey === null || $key > $effectiveKey)) {
                $effectiveKey = $key;
                $effectiveRate = $basePay;
            }
        }

        // All future-dated (a scheduled raise for a brand-new hire) — better to
        // carry that rate than to report a zero-cost schedule.
        $rate = $effectiveRate ?? $newestRate;

        return $rate === null ? null : number_format((float) $rate, 4, '.', '');
    }

    /** The employee's current position, as text. Hiring sorts newest-first. */
    protected function currentPositionLabel(array $positions): ?string
    {
        foreach ($positions as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $label = $this->stringOrNull(data_get($entry, 'position.label'))
                ?? $this->stringOrNull(data_get($entry, 'label'));

            if ($label !== null) {
                return $label;
            }
        }

        return null;
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

    /**
     * Membership rows for a delta that changed `stores` but NOT
     * `status_histories`.
     *
     * We no longer keep a status-history table to reconstruct per-store status
     * from, so each surviving store's status is carried forward from the
     * membership row we already hold. That is safe because employee_store.status
     * is rewritten on every event carrying `stores` OR `status_histories` — it
     * never drifts from the history, it IS the materialised view of it.
     *
     * `active` is recomputed from the carried status rather than trusting the
     * stored boolean, so there is exactly one rule deriving it.
     *
     * Known divergence from the old behaviour: a store being added here takes
     * the employee's overall current_status, whereas the history table could
     * have held a store-specific status shipped by an earlier
     * status_histories-only delta. That needs multi-store employees with
     * divergent per-store status AND a split delta, and is the accepted cost of
     * not holding an HR record.
     *
     * @return array<int, array{store_number:string,status:?string,active:bool,effective_date:?string}>
     */
    protected function carryForwardMembershipRows(int $employeeId, array $stores, ?string $currentStatus): array
    {
        $existing = EmployeeStore::query()
            ->where('employee_id', $employeeId)
            ->pluck('status', 'store_number');

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

            $status = $this->stringOrNull($existing[$storeNumber] ?? null) ?? $currentStatus;

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
