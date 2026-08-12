<?php

namespace App\Services\Tcp\Exceptions;

use App\Models\Employee;
use App\Services\Scheduling\Exceptions\SchedulingException;

/**
 * The employee exists in hiring but has no TCP Manager+ counterpart, so they
 * cannot clock in and no worked hours can be attributed to them.
 *
 * TCP is the system of record for employees and HiringPizza owns that write, so
 * this is resolved upstream — never by creating the person here.
 */
class EmployeeNotInTcpException extends SchedulingException
{
    public function __construct(public readonly Employee $employee)
    {
        $name = trim("{$employee->first_name} {$employee->last_name}");

        parent::__construct(
            "{$name} isn't set up in the time clock yet.",
            'EMPLOYEE_NOT_IN_TCP',
            409,
            [
                'employee_id' => (string) $employee->id,
                'employee_name' => $name,
                // Deliberately different from EMPLOYEE_NOT_SYNCED: that one is
                // about Humanity and self-heals over NATS. This one needs the
                // person to exist in TCP, which is a hiring-side action.
                'resolution' => 'The employee must be created in TCP Manager+ (HiringPizza owns that write).',
            ],
        );
    }
}
