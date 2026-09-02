<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesStore;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\ScheduleAvailabilityOverride;
use App\Services\Scheduling\AvailabilityProjector;
use App\Services\Scheduling\WeekResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AvailabilityController extends Controller
{
    use ResolvesStore;

    public function __construct(
        private readonly AvailabilityProjector $projector,
        private readonly WeekResolver $weeks,
    ) {
    }

    /** Blocked windows for a week, profile-derived plus manager overrides. */
    public function index(Request $request, string $storeId): JsonResponse
    {
        $store = $this->resolveStore($storeId);
        $settings = $store->settings();

        $weekStart = $this->weeks->normalizeWeekStart(
            $request->string('week_start')->toString() ?: now()->toDateString(),
            $this->weeks->weekStartDow($settings)
        );

        $employees = Employee::query()
            ->with('availabilityDays.times')
            ->assignedToStore((string) $store->store_number)
            ->schedulable()
            ->get();

        return response()->json([
            'data' => $this->projector->forWeek($store, $employees, $weekStart, $settings),
        ]);
    }

    public function store(Request $request, string $storeId): JsonResponse
    {
        $store = $this->resolveStore($storeId);

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'min:1'],
            'scope' => ['required', 'in:weekly,date'],
            // Canonical 0=Sun..6=Sat. The UI's Tuesday-based day_index is
            // converted at its own edge, never stored.
            'day_of_week' => ['required_if:scope,weekly', 'nullable', 'integer', 'between:0,6'],
            'specific_date' => ['required_if:scope,date', 'nullable', 'date_format:Y-m-d'],
            'all_day' => ['nullable', 'boolean'],
            'start_time' => ['required_if:all_day,false', 'nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'end_time' => ['required_if:all_day,false', 'nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'reason' => ['nullable', 'string', 'max:255'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $override = ScheduleAvailabilityOverride::query()->create($validated + [
            'store_id' => $store->id,
            'all_day' => $validated['all_day'] ?? true,
        ]);

        return response()->json(['data' => $override], 201);
    }

    public function destroy(string $storeId, string $overrideId): JsonResponse
    {
        $store = $this->resolveStore($storeId);

        $id = $this->parseOverrideId($overrideId);

        $override = $id === null ? null : ScheduleAvailabilityOverride::query()
            ->where('store_id', $store->id)
            ->find($id);

        if ($override === null) {
            throw new NotFoundHttpException("Availability override {$overrideId} not found.");
        }

        $override->delete();

        return response()->json(null, 204);
    }

    /**
     * The week grid displays overrides using the projector's composite id
     * (e.g. "override-42-3") and hands that same string back on delete,
     * rather than the bare database id — so both forms have to resolve here.
     */
    private function parseOverrideId(string $overrideId): ?int
    {
        if (ctype_digit($overrideId)) {
            return (int) $overrideId;
        }

        if (preg_match('/^override-(\d+)-\d+$/', $overrideId, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }
}
