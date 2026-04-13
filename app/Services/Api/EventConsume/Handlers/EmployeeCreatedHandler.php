<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\Employee;
use App\Services\EventConsume\EventHandlerInterface;
use Illuminate\Support\Facades\DB;

class EmployeeCreatedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $employee = data_get($event, 'data.employee');
        $included = data_get($event, 'data.included', []);

        if (!is_array($employee)) {
            throw new \Exception('EmployeeCreatedHandler: missing employee data');
        }

        $id = $this->asInt(data_get($employee, 'id'));
        $storeId = $this->asInt(data_get($employee, 'store_id'));

        if ($id <= 0) {
            throw new \Exception('EmployeeCreatedHandler: invalid id');
        }

        // ✅ Name
        $profile = data_get($included, 'profile', []);
        $name = trim(
            (data_get($profile, 'first_name', '') . ' ') .
            (data_get($profile, 'middle_name', '') . ' ') .
            (data_get($profile, 'last_name', ''))
        );

        // ✅ Contacts
        $contacts = data_get($included, 'contacts', []);
        $phone = null;
        $email = null;

        foreach ($contacts as $c) {
            if (($c['contact_type'] ?? null) === 'phone') {
                $phone = $c['contact_value'];
            }
            if (($c['contact_type'] ?? null) === 'email') {
                $email = $c['contact_value'];
            }
        }

        // ✅ Hire date (latest status_date)
        $statusHistory = data_get($included, 'status_history', []);
        $hireDate = null;

        if (!empty($statusHistory)) {
            $dates = array_column($statusHistory, 'status_date');
            $hireDate = max($dates);
        }

        // ✅ Status (optional → simple default)
        $status = 'hired';

        DB::transaction(function () use ($id, $storeId, $name, $phone, $email, $hireDate, $status) {

            Employee::query()->updateOrCreate(
                ['id' => $id],
                [
                    'store_id' => $storeId,
                    'name' => $name ?: null,
                    'phone' => $phone,
                    'email' => $email,
                    'hire_date' => $hireDate,
                    'status' => $status,
                ]
            );
        });
    }

    private function asInt(mixed $v): int
    {
        if (is_int($v)) return $v;
        if (is_string($v) && ctype_digit($v)) return (int) $v;
        if (is_numeric($v)) return (int) $v;
        return 0;
    }
}