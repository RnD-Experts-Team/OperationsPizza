<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Concerns\ResolvesStore;
use App\Services\Scheduling\EmployeePresenter;
use App\Services\Scheduling\LaborCostCalculator;
use App\Services\Scheduling\ScheduleWeekAssembler;
use App\Services\Scheduling\StaffingHistoryService;
use App\Services\Scheduling\StoreTimezoneResolver;
use App\Services\Scheduling\WeekResolver;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    use ResolvesStore;

    public function __construct(
        private readonly ScheduleWeekAssembler $assembler,
        private readonly EmployeePresenter $employees,
        private readonly LaborCostCalculator $labor,
        private readonly StaffingHistoryService $staffingHistory,
        private readonly StoreTimezoneResolver $timezones,
        private readonly WeekResolver $weeks,
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

    /**
     * Staffing history for the schedule builder: how many people were actually
     * on the clock each hour, per weekday, over a window of business weeks.
     *
     * Without start_date/end_date the window is the four complete business
     * weeks before the week that contains today (in the store's timezone).
     */
    public function insights(Request $request, string $storeId): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d', 'required_with:end_date'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'required_with:start_date', 'after_or_equal:start_date'],
        ]);

        $store = $this->resolveStore($storeId);

        if (isset($validated['start_date'])) {
            $start = CarbonImmutable::parse($validated['start_date'])->startOfDay();
            $end = CarbonImmutable::parse($validated['end_date'])->startOfDay();
        } else {
            $today = CarbonImmutable::now($this->timezones->for($store))->startOfDay();
            $thisWeek = $this->weeks->weekStartFor($today, $this->weeks->weekStartDow($store->settings()));
            $start = $thisWeek->subDays(28);
            $end = $thisWeek->subDay();
        }

        return response()->json(['data' => $this->staffingHistory->build($store, $start, $end)]);
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
