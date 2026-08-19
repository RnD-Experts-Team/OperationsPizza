<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesStore;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\Tcp\Dto\TcpWorkSegment;
use App\Services\Tcp\TcpClockService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Clocking, against TCP Manager+.
 *
 * Deliberately separate from the scheduling endpoints: shifts live in Humanity,
 * worked time lives in TCP, and conflating them is what makes these
 * integrations rot. Nothing here touches `shifts`.
 */
class ClockController extends Controller
{
    use ResolvesStore;

    public function __construct(private readonly TcpClockService $clock)
    {
    }

    public function clockIn(Request $request, string $storeId, int $employeeId): JsonResponse
    {
        $validated = $this->validatePunch($request);

        $store = $this->resolveStore($storeId);
        $employee = $this->findEmployee($store->store_number, $employeeId);

        $segment = $this->clock->clockIn(
            $store,
            $employee,
            $this->momentFrom($validated),
            $validated['position_label'] ?? null,
            $request
        );

        return response()->json(['data' => $this->present($segment)], 201);
    }

    public function clockOut(Request $request, string $storeId, int $employeeId): JsonResponse
    {
        $validated = $this->validatePunch($request);

        $store = $this->resolveStore($storeId);
        $employee = $this->findEmployee($store->store_number, $employeeId);

        $segment = $this->clock->clockOut($store, $employee, $this->momentFrom($validated), $request);

        return response()->json(['data' => $this->present($segment)]);
    }

    public function breakStart(Request $request, string $storeId, int $employeeId): JsonResponse
    {
        $validated = $request->validate([
            'at' => ['nullable', 'date'],
            'break_type' => ['nullable', 'integer', 'min:0'],
        ]);

        $store = $this->resolveStore($storeId);
        $employee = $this->findEmployee($store->store_number, $employeeId);

        $segment = $this->clock->breakStart(
            $store,
            $employee,
            (int) ($validated['break_type'] ?? 0),
            $this->momentFrom($validated),
            $request
        );

        return response()->json(['data' => $this->present($segment)]);
    }

    public function breakEnd(Request $request, string $storeId, int $employeeId): JsonResponse
    {
        $validated = $this->validatePunch($request);

        $store = $this->resolveStore($storeId);
        $employee = $this->findEmployee($store->store_number, $employeeId);

        $segment = $this->clock->breakEnd(
            $store,
            $employee,
            $this->momentFrom($validated),
            $validated['position_label'] ?? null,
            $request
        );

        return response()->json(['data' => $this->present($segment)]);
    }

    /** Who is on the clock right now, read live from TCP. */
    public function status(string $storeId, int $employeeId): JsonResponse
    {
        $store = $this->resolveStore($storeId);
        $employee = $this->findEmployee($store->store_number, $employeeId);

        if (!$employee->isLinkedToTcp()) {
            return response()->json(['data' => [
                'employee_id' => (string) $employee->id,
                'linked_to_tcp' => false,
                'clocked_in' => false,
                'segment' => null,
            ]]);
        }

        $segment = $this->clock->currentSegment($employee);

        return response()->json(['data' => [
            'employee_id' => (string) $employee->id,
            'linked_to_tcp' => true,
            'clocked_in' => $segment !== null,
            'segment' => $segment === null ? null : $this->present($segment),
        ]]);
    }

    private function validatePunch(Request $request): array
    {
        return $request->validate([
            // Omit to punch now. Supplying a time is for corrections, and TCP
            // applies its own edit rules to those.
            'at' => ['nullable', 'date'],
            'position_label' => ['nullable', 'string', 'max:190'],
        ]);
    }

    private function momentFrom(array $validated): ?CarbonImmutable
    {
        return isset($validated['at']) ? CarbonImmutable::parse($validated['at']) : null;
    }

    private function findEmployee(string $storeNumber, int $employeeId): Employee
    {
        $employee = Employee::query()
            ->assignedToStore($storeNumber)
            ->find($employeeId);

        if ($employee === null) {
            throw new NotFoundHttpException("Employee {$employeeId} is not assigned to store {$storeNumber}.");
        }

        return $employee;
    }

    private function present(TcpWorkSegment $segment): array
    {
        return [
            'work_segment_id' => $segment->id,
            'tcp_employee_id' => $segment->employeeId,
            'job_code_id' => $segment->jobCodeId,
            'time_in' => $segment->timeIn,
            'time_out' => $segment->timeOut,
            'actual_time_in' => $segment->actualTimeIn,
            'actual_time_out' => $segment->actualTimeOut,
            'is_open' => $segment->isOpen(),
            'has_missed_punch' => $segment->hasMissedPunch(),
            'break_length' => $segment->breakLength,
        ];
    }
}
