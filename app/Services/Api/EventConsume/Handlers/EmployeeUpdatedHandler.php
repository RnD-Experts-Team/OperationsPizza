<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\Employee;
use App\Services\EventConsume\EventHandlerInterface;
use Illuminate\Support\Facades\DB;

class EmployeeUpdatedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $id = $this->asInt(data_get($event, 'data.employee_id'));

        if ($id <= 0) {
            throw new \Exception('EmployeeUpdatedHandler: invalid id');
        }

        $included = data_get($event, 'data.included', []);

        DB::transaction(function () use ($id, $included) {

            $employee = Employee::query()->find($id);

            if (!$employee) {
                return;
            }

            $update = [];

            // Name
            if (isset($included['profile']['changes'])) {
                $profile = $included['profile']['changes'];

                $first = data_get($profile, 'first_name.to', '');
                $middle = data_get($profile, 'middle_name.to', '');
                $last = data_get($profile, 'last_name.to', '');

                $update['name'] = trim("$first $middle $last");
            }

            // Contacts
            if (isset($included['contacts']['items'])) {
                foreach ($included['contacts']['items'] as $c) {
                    if ($c['contact_type'] === 'phone') {
                        $update['phone'] = $c['contact_value'];
                    }
                    if ($c['contact_type'] === 'email') {
                        $update['email'] = $c['contact_value'];
                    }
                }
            }

            // Status history
            if (isset($included['status_history']['items'])) {
                $dates = array_column($included['status_history']['items'], 'status_date');
                if (!empty($dates)) {
                    $update['hire_date'] = max($dates);
                }
            }

            if (!empty($update)) {
                $employee->update($update);
            }
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