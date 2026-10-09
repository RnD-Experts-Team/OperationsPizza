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
        private readonly bool $awaitingTcpConnector = false,
    ) {
        $name = trim("{$employee->first_name} {$employee->last_name}");

        parent::__construct(
            $awaitingTcpConnector
                ? "{$name} is already linked to TCP but hasn't reached Humanity yet."
                : "{$name} isn't set up in the scheduling system yet.",
            'EMPLOYEE_NOT_SYNCED',
            409,
            [
                'employee_id' => (string) $employee->id,
                'employee_name' => $name,
                'sync_status' => $awaitingTcpConnector ? 'awaiting_tcp_connector' : ($syncRequest?->status ?? 'requested'),
                'sync_requested_at' => $syncRequest?->requested_at?->toIso8601String(),
                'sync_last_error' => $syncRequest?->last_error,
                'retry_after_seconds' => 5,
                'poll_url' => "/api/v1/stores/{$storeNumber}/employees/{$employee->id}/sync-status",
            ],
        );
    }

    /**
     * TCP linkage already happened; the only remaining step is TCP's own
     * connector carrying this employee into Humanity (or a
     * humanity:sync-employees backstop run). Nothing to ask HiringPizza for —
     * firing tcp_sync_requested here would just re-request a TCP push that
     * already succeeded, same reasoning as EmployeeSyncController::request()'s
     * isLinkedToTcp() guard.
     */
    public static function awaitingTcpConnector(Employee $employee, string $storeNumber): self
    {
        return new self($employee, null, $storeNumber, true);
    }
}
