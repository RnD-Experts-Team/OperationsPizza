<?php

namespace App\Services\Scheduling;

use App\Jobs\PublishOutboxEventJob;
use App\Models\Employee;
use App\Models\EmployeeSyncRequest;
use App\Services\OperationsEvents\OperationsEventFactory;
use App\Services\OperationsEvents\OperationsOutboxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * HiringPizza is the ONLY writer of employees into Humanity, so when scheduling
 * meets an unlinked employee we ask over NATS and wait rather than creating the
 * staff record ourselves — two writers to one external system is how duplicate
 * people happen.
 */
class EmployeeSyncRequestService
{
    /** Don't re-publish for the same employee inside this window. */
    private const DEDUPE_SECONDS = 60;

    public function __construct(
        private readonly OperationsEventFactory $events,
        private readonly OperationsOutboxService $outbox,
    ) {
    }

    public function request(
        Employee $employee,
        string $storeNumber,
        ?int $actorUserId = null,
        ?Request $httpRequest = null,
    ): EmployeeSyncRequest {
        return DB::transaction(function () use ($employee, $storeNumber, $actorUserId, $httpRequest) {
            $existing = EmployeeSyncRequest::query()
                ->where('employee_id', $employee->id)
                ->lockForUpdate()
                ->first();

            // A manager clicking twice, or several shifts saved in a row, must
            // not fan out into a stream of identical NATS events.
            if ($existing
                && $existing->status === 'requested'
                && $existing->requested_at !== null
                && $existing->requested_at->gt(now()->subSeconds(self::DEDUPE_SECONDS))
            ) {
                return $existing;
            }

            $syncRequest = EmployeeSyncRequest::query()->updateOrCreate(
                ['employee_id' => $employee->id],
                [
                    'status' => 'requested',
                    'requested_by_user_id' => $actorUserId,
                    'requested_at' => now(),
                    'attempts' => ($existing->attempts ?? 0) + 1,
                    'fulfilled_at' => null,
                ]
            );

            $envelope = $this->events->make('operations.v1.employee.humanity_sync_requested', [
                'employee_id' => $employee->id,
                'store_number' => $storeNumber,
                'requested_by' => $actorUserId,
                'requested_at' => now()->utc()->toIso8601String(),
                'attempts' => $syncRequest->attempts,
            ], $httpRequest);

            $row = $this->outbox->record('operations.v1.employee.humanity_sync_requested', $envelope);

            PublishOutboxEventJob::dispatch($row->id);

            return $syncRequest;
        });
    }

    public function statusFor(Employee $employee): array
    {
        $syncRequest = $employee->syncRequest()->first();

        return [
            'employee_id' => (string) $employee->id,
            'employee_name' => trim("{$employee->first_name} {$employee->last_name}"),
            'humanity_employee_id' => $employee->humanity_employee_id,
            'synced' => $employee->isLinkedToHumanity(),
            'sync_request' => $syncRequest === null ? null : [
                'status' => $syncRequest->status,
                'requested_at' => $syncRequest->requested_at?->toIso8601String(),
                'fulfilled_at' => $syncRequest->fulfilled_at?->toIso8601String(),
                'attempts' => (int) $syncRequest->attempts,
                'last_error' => $syncRequest->last_error,
            ],
        ];
    }
}
