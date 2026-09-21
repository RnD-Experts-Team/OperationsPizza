<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesStore;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\TcpWorkSegment as WorkSegmentRow;
use App\Services\Tcp\Dto\TcpWorkSegment;
use App\Services\Scheduling\StoreTimezoneResolver;
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

    public function __construct(
        private readonly TcpClockService $clock,
        private readonly StoreTimezoneResolver $timezones,
    ) {
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

    /**
     * Whether this employee is on the clock.
     *
     * A local read. It is correct for a punch made at a physical clock or in
     * TCP's own app, because the sync persists open segments — which is exactly
     * what the previous clock-state table could not do, since only this API ever
     * wrote to it.
     */
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
            'segment' => $segment === null ? null : $this->presentRow($segment),
        ]]);
    }

    /**
     * The whole store's board: everyone currently on the clock.
     *
     * One indexed query, no TCP call. Worth stating plainly, because this was
     * not previously possible — answering it meant a vendor call per employee,
     * and a dashboard polling that would have drained the 2500/day quota on its
     * own.
     */
    public function onTheClock(string $storeId): JsonResponse
    {
        $store = $this->resolveStore($storeId);

        $segments = $this->clock->onTheClock($store);

        return response()->json([
            'data' => $segments->map(fn (WorkSegmentRow $segment) => [
                'employee_id' => (string) $segment->employee_id,
                'employee_name' => trim(
                    ($segment->employee?->first_name ?? '') . ' ' . ($segment->employee?->last_name ?? '')
                ) ?: null,
                'since' => $segment->starts_at_utc?->toIso8601String(),
                'minutes_so_far' => $segment->starts_at_utc === null
                    ? null
                    : (int) $segment->starts_at_utc->diffInMinutes(now()),
                'segment' => $this->presentRow($segment),
            ])->values(),
        ]);
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

    /**
     * A UTC-stored instant, back in the store's own wall clock.
     *
     * The four times on a segment describe the same shift, so presenting some
     * of them as local and others as UTC would make a client convert one before
     * it could compare them - and quietly invite the bug where it forgets.
     */
    private function localise(WorkSegmentRow $segment, ?\Carbon\CarbonInterface $at): ?string
    {
        if ($at === null || $segment->store === null) {
            return $at?->toDateTimeString();
        }

        return CarbonImmutable::parse($at)
            ->setTimezone($this->timezones->for($segment->store))
            ->toDateTimeString();
    }

    /**
     * A stored segment, as the API shows it.
     *
     * Deliberately the same shape as present() below, so a client does not have
     * to care whether a segment came back from a punch or out of our table.
     */
    private function presentRow(WorkSegmentRow $segment): array
    {
        return [
            'work_segment_id' => $segment->tcp_work_segment_id,
            'tcp_employee_id' => $segment->tcp_employee_id,
            'job_code_id' => $segment->tcp_job_code_id,
            'time_in' => $segment->time_in?->toDateTimeString(),
            'time_out' => $segment->time_out?->toDateTimeString(),
            'actual_time_in' => $this->localise($segment, $segment->actual_punch_in_at),
            'actual_time_out' => $this->localise($segment, $segment->actual_punch_out_at),
            'is_open' => $segment->isOpen(),
            'has_missed_punch' => $segment->hasMissedPunch(),
            // Where the punch was made, which is the question this whole sync
            // exists to answer: `discovered` means it happened somewhere other
            // than here.
            'origin' => $segment->origin,
        ];
    }

    /**
     * A segment straight off a punch response, before it has been stored.
     *
     * Times are normalised to the format presentRow() emits: TCP returns
     * "2026-08-06T09:00:00" and a stored row reads "2026-08-06 09:00:00", and a
     * client should not have to tell apart two spellings of one wall clock
     * depending on which endpoint produced it.
     */
    private function present(TcpWorkSegment $segment): array
    {
        $wallClock = fn (?string $value) => $value === null
            ? null
            : CarbonImmutable::parse($value)->toDateTimeString();

        return [
            'work_segment_id' => $segment->id,
            'tcp_employee_id' => $segment->employeeId,
            'job_code_id' => $segment->jobCodeId,
            'time_in' => $wallClock($segment->timeIn),
            'time_out' => $wallClock($segment->timeOut),
            'actual_time_in' => $wallClock($segment->actualTimeIn),
            'actual_time_out' => $wallClock($segment->actualTimeOut),
            'is_open' => $segment->isOpen(),
            'has_missed_punch' => $segment->hasMissedPunch(),
            // A segment coming straight off a punch response is, by definition,
            // one we just made. Stated rather than omitted so the two shapes are
            // genuinely interchangeable — `break_length` used to appear only
            // here, and `origin` only on the stored shape, which made the
            // "same shape" claim above false.
            'origin' => WorkSegmentRow::ORIGIN_PUNCH,
        ];
    }
}
