<?php

namespace App\Services\Scheduling\Exceptions;

use App\Models\Employee;
use App\Models\EmployeeSyncRequest;

/**
 * The employee exists in hiring but has no Humanity counterpart, so we cannot
 * schedule them yet. HiringPizza owns the write, so a sync has been requested
 * over NATS and this error tells the UI how to wait for it.
 *
 * Deliberately resumable: the manager's typed shift must survive, so the
 * response carries a poll URL rather than just failing.
 */
class EmployeeNotSyncedException extends SchedulingException
{
    public function __construct(
        public readonly Employee $employee,
        public readonly ?EmployeeSyncRequest $syncRequest,
        public readonly string $storeNumber,
    ) {
        parent::__construct(
            trim("{$employee->first_name} {$employee->last_name}") . " isn't set up in the scheduling system yet.",
            'EMPLOYEE_NOT_SYNCED',
            409,
            [
                'employee_id' => (string) $employee->id,
                'employee_name' => trim("{$employee->first_name} {$employee->last_name}"),
                'sync_status' => $syncRequest?->status ?? 'requested',
                'sync_requested_at' => $syncRequest?->requested_at?->toIso8601String(),
                'sync_last_error' => $syncRequest?->last_error,
                'retry_after_seconds' => 5,
                'poll_url' => "/api/v1/stores/{$storeNumber}/employees/{$employee->id}/sync-status",
            ],
        );
    }
}
