<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Concerns\ResolvesStore;
use App\Services\Scheduling\EmployeePresenter;
use App\Services\Scheduling\LaborCostCalculator;
use App\Services\Scheduling\ScheduleWeekAssembler;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    use ResolvesStore;

    public function __construct(
        private readonly ScheduleWeekAssembler $assembler,
        private readonly EmployeePresenter $employees,
        private readonly LaborCostCalculator $labor,
    ) {
    }

    /**
     * The bootstrap call: one round trip that replaces the grid's mock data
     * entirely — roster, shifts, actuals, availability, time off, conflicts,
     * stats and the published record.
     */
    public function week(Request $request, string $storeId): JsonResponse
    {
        $validated = $request->validate([
            'week_start' => ['nullable', 'date_format:Y-m-d'],
            'mode' => ['nullable', 'in:planned,actual,both'],
            'department' => ['nullable', 'string', 'max:190'],
            'search' => ['nullable', 'string', 'max:190'],
        ]);

        $store = $this->resolveStore($storeId);

        return response()->json([
            'data' => $this->assembler->assemble(
                $store,
                $validated['week_start'] ?? now()->toDateString(),
                $validated
            ),
        ]);
    }

    /** Roster only, for pickers that don't need a whole week. */
    public function employees(Request $request, string $storeId): JsonResponse
    {
        $store = $this->resolveStore($storeId);

        $employees = Employee::query()
            ->assignedToStore((string) $store->store_number)
            ->schedulable()
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request) {
                $search = $request->string('search')->toString();

                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%");
                });
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        $this->labor->preload($employees->pluck('id')->all());

        return response()->json([
            'data' => $this->employees->present($employees, $store, $this->labor),
        ]);
    }

    /** Humanity positions mapped to this store — the department filter list. */
    public function departments(string $storeId): JsonResponse
    {
        return response()->json([
            'data' => $this->employees->departments($this->resolveStore($storeId)),
        ]);
    }
}
