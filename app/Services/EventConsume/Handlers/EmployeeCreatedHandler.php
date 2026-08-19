<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\Employee;
use App\Services\EventConsume\EventHandlerInterface;
use App\Services\EventConsume\Handlers\Concerns\ReplicatesEmployees;
use Illuminate\Support\Facades\DB;

/**
 * hiring.v1.employee.created carries a FULL snapshot at data.employee, so every
 * collection is present and every collection is rebuilt.
 */
class EmployeeCreatedHandler implements EventHandlerInterface
{
    use ReplicatesEmployees;

    public function handle(array $event): void
    {
        $payload = $this->extractEmployeePayload($event);

        $id = $this->resolveEmployeeId($event, $payload);
        if ($id <= 0) {
            throw new \Exception('EmployeeCreatedHandler: missing/invalid employee id');
        }

        $firstName = $this->stringOrNull(data_get($payload, 'first_name'));
        $lastName = $this->stringOrNull(data_get($payload, 'last_name'));

        if ($firstName === null || $lastName === null) {
            throw new \Exception("EmployeeCreatedHandler: missing first_name or last_name for employee {$id}");
        }

        $statusHistories = (array) data_get($payload, 'status_histories', []);
        $memberships = $this->buildMembershipRows(
            (array) data_get($payload, 'stores', []),
            $statusHistories
        );

        DB::transaction(function () use ($id, $payload, $event, $firstName, $lastName, $statusHistories, $memberships) {
            $employee = Employee::withTrashed()->find($id);

            $attributes = [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'active' => $this->anyActive($memberships),
                'current_status' => $this->latestStatus($statusHistories),
                'hourly_rate' => $this->currentHourlyRate((array) data_get($payload, 'pay_histories', [])),
                'position_label' => $this->currentPositionLabel((array) data_get($payload, 'positions', [])),
                'hiring_event_at' => $this->eventTime($event),
            ];

            if ($employee === null) {
                $employee = Employee::query()->create(['id' => $id] + $attributes);
            } else {
                $employee->fill($attributes);

                if ($employee->trashed()) {
                    $employee->restore();
                }

                $employee->save();
            }

            $this->replaceAvailability($id, (array) data_get($payload, 'availability_days', []));
            $this->replaceMemberships($id, $memberships);

            // Last: it may set humanity_employee_id, and it saves the model.
            $this->syncExternalIds($employee, (array) data_get($payload, 'ids', []));
        });
    }
}
