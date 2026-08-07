<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesStore;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\Scheduling\EmployeeSyncRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The UI's half of the unsynced-employee loop: it polls `sync-status` after a
 * 409 and can re-request a sync manually if the first one never lands.
 */
class EmployeeSyncController extends Controller
{
    use ResolvesStore;

    public function __construct(private readonly EmployeeSyncRequestService $syncRequests)
    {
    }

    public function status(string $storeId, int $employeeId): JsonResponse
    {
        $this->resolveStore($storeId);

        return response()->json(['data' => $this->syncRequests->statusFor($this->findEmployee($employeeId))]);
    }

    /** Manual retry. 202 because HiringPizza does the actual work, over NATS. */
    public function request(Request $request, string $storeId, int $employeeId): JsonResponse
    {
        $store = $this->resolveStore($storeId);
        $employee = $this->findEmployee($employeeId);

        if ($employee->isLinkedToHumanity()) {
            return response()->json(['data' => $this->syncRequests->statusFor($employee)]);
        }

        $this->syncRequests->request($employee, (string) $store->store_number, $request->user()?->id, $request);

        return response()->json(['data' => $this->syncRequests->statusFor($employee->fresh())], 202);
    }

    private function findEmployee(int $employeeId): Employee
    {
        $employee = Employee::query()->find($employeeId);

        if ($employee === null) {
            throw new NotFoundHttpException("Employee {$employeeId} not found.");
        }

        return $employee;
    }
}
