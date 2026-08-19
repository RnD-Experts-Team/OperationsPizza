<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\Employee;
use App\Models\EmployeeStore;
use App\Services\EventConsume\EventHandlerInterface;
use App\Services\EventConsume\Handlers\Concerns\ReplicatesEmployees;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * hiring.v1.employee.updated carries ONLY data.changed_fields.<field>.{from,to}
 * — no snapshot. A field absent from the delta must be left alone; a collection
 * present in the delta arrives whole and replaces ours entirely.
 */
class EmployeeUpdatedHandler implements EventHandlerInterface
{
    use ReplicatesEmployees;

    public function handle(array $event): void
    {
        $payload = $this->extractEmployeePayload($event);

        $id = $this->resolveEmployeeId($event, $payload);
        if ($id <= 0) {
            throw new \Exception('EmployeeUpdatedHandler: missing/invalid employee id');
        }

        DB::transaction(function () use ($id, $event, $payload) {
            $employee = Employee::withTrashed()->lockForUpdate()->find($id);

            // Ordering isn't guaranteed; throwing lets JetStreamConsumer NAK
            // and retry once the create has landed.
            if ($employee === null) {
                throw new \Exception("EmployeeUpdatedHandler: employee {$id} not synced yet");
            }

            if (!$this->isNewerThanApplied($employee, $event)) {
                Log::info('Skipping out-of-order hiring employee event', [
                    'employee_id' => $id,
                    'event_time' => data_get($event, 'time'),
                    'applied_at' => $employee->hiring_event_at?->toIso8601String(),
                ]);

                return;
            }

            $changed = $this->changedFields($event);
            $update = [];

            foreach (['first_name', 'last_name'] as $field) {
                if (array_key_exists($field, $changed)) {
                    $update[$field] = $this->resolveScalar($event, $payload, $field);
                }
            }

            // first_name/last_name are NOT NULL — a delta that nulls them is
            // malformed, so keep what we have rather than failing the insert.
            foreach (['first_name', 'last_name'] as $required) {
                if (array_key_exists($required, $update) && $update[$required] === null) {
                    unset($update[$required]);
                }
            }

            $payHistories = $this->resolveCollection($event, $payload, 'pay_histories');
            if ($payHistories !== null) {
                $update['hourly_rate'] = $this->currentHourlyRate($payHistories);
            }

            $availability = $this->resolveCollection($event, $payload, 'availability_days');
            if ($availability !== null) {
                $this->replaceAvailability($id, $availability);
            }

            $positions = $this->resolveCollection($event, $payload, 'positions');
            if ($positions !== null) {
                $update['position_label'] = $this->currentPositionLabel($positions);
            }

            $stores = $this->resolveCollection($event, $payload, 'stores');
            $statusHistories = $this->resolveCollection($event, $payload, 'status_histories');

            if ($statusHistories !== null) {
                // current_status is no longer just a denormalisation — with the
                // status-history table gone it is the only fallback a newly
                // added store's membership has.
                $update['current_status'] = $this->latestStatus($statusHistories);
            }

            $memberships = $this->resolveMemberships($id, $stores, $statusHistories, $employee);

            if ($memberships !== null) {
                $this->replaceMemberships($id, $memberships);
                $update['active'] = $this->anyActive($memberships);
            }

            $update['hiring_event_at'] = $this->eventTime($event);

            $employee->fill($update)->save();

            $ids = $this->resolveCollection($event, $payload, 'ids');
            if ($ids !== null) {
                // This is how a Humanity link arrives after HiringPizza pushes
                // the employee — it fulfils any open sync request.
                $this->syncExternalIds($employee, $ids);
            }
        });
    }

    /**
     * Membership rows for an update, or null to leave memberships untouched.
     *
     * Returning null when NEITHER field is in the delta is load-bearing: it is
     * what stops an unrelated update (a name change, say) from recomputing
     * `active` off an empty array and deactivating the employee — which would
     * drop them from every roster and 401 their auth token.
     *
     * @return array<int, array>|null
     */
    private function resolveMemberships(
        int $employeeId,
        ?array $stores,
        ?array $statusHistories,
        Employee $employee
    ): ?array {
        if ($stores === null && $statusHistories === null) {
            return null;
        }

        if ($stores !== null) {
            // Both present: the wire is authoritative for each store's status.
            if ($statusHistories !== null) {
                return $this->buildMembershipRows($stores, $statusHistories);
            }

            // Stores only: carry each surviving store's status forward.
            return $this->carryForwardMembershipRows(
                $employeeId,
                $stores,
                $this->stringOrNull($employee->current_status)
            );
        }

        // Only status changed: keep the store list, recompute each status.
        $existing = EmployeeStore::query()->where('employee_id', $employeeId)->get();

        $syntheticStores = $existing->map(fn (EmployeeStore $row) => [
            'store' => ['store_number' => $row->store_number],
            'effective_date' => $row->effective_date?->toDateString(),
        ])->all();

        return $this->buildMembershipRows($syntheticStores, $statusHistories);
    }
}
