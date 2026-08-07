<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
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

            foreach (['first_name', 'middle_name', 'last_name', 'gender', 'employment_type'] as $field) {
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

            if (array_key_exists('obsession', $changed)) {
                $update['birth_date'] = $this->stringOrNull(data_get($changed, 'obsession.to.birth_date'));
                $update['image_url'] = $this->stringOrNull(data_get($changed, 'obsession.to.image_url'));
            }

            $contacts = $this->resolveCollection($event, $payload, 'contacts');
            if ($contacts !== null) {
                $this->replaceContacts($id, $contacts);
            }

            $payHistories = $this->resolveCollection($event, $payload, 'pay_histories');
            if ($payHistories !== null) {
                $this->replacePayHistories($id, $payHistories);
            }

            $availability = $this->resolveCollection($event, $payload, 'availability_days');
            if ($availability !== null) {
                $this->replaceAvailability($id, $availability);
            }

            $positions = $this->resolveCollection($event, $payload, 'positions');
            if ($positions !== null) {
                $this->replacePositions($id, $positions);
            }

            $stores = $this->resolveCollection($event, $payload, 'stores');
            $statusHistories = $this->resolveCollection($event, $payload, 'status_histories');

            if ($statusHistories !== null) {
                $this->replaceStatusHistories($id, $statusHistories);
                $update['current_status'] = $this->latestStatus($statusHistories);
                $update['current_status_effective_date'] = $this->latestStatusEffectiveDate($statusHistories);
            }

            $memberships = $this->resolveMemberships($id, $stores, $statusHistories);

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
     * Status and store list change independently, so when only one of them is
     * in the delta the other has to be reconstructed from what we already hold.
     *
     * @return array<int, array>|null
     */
    private function resolveMemberships(int $employeeId, ?array $stores, ?array $statusHistories): ?array
    {
        if ($stores === null && $statusHistories === null) {
            return null;
        }

        if ($stores !== null) {
            return $this->buildMembershipRows($stores, $statusHistories ?? $this->storedStatusHistories($employeeId));
        }

        // Only status changed: keep the store list, recompute each status.
        $existing = EmployeeStore::query()->where('employee_id', $employeeId)->get();

        $syntheticStores = $existing->map(fn (EmployeeStore $row) => [
            'store' => ['store_number' => $row->store_number],
            'effective_date' => $row->effective_date?->toDateString(),
        ])->all();

        return $this->buildMembershipRows($syntheticStores, $statusHistories);
    }

    /** Our replicated histories, shaped like the event payload. */
    private function storedStatusHistories(int $employeeId): array
    {
        return EmployeeStatusHistory::query()
            ->where('employee_id', $employeeId)
            ->get()
            ->map(fn (EmployeeStatusHistory $row) => [
                'id' => $row->id,
                'status' => $row->status,
                'effective_date' => $row->effective_date?->toDateString(),
                'store' => ['store_number' => $row->store_number],
            ])
            ->all();
    }
}
