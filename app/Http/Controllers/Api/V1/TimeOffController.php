<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesStore;
use App\Http\Controllers\Controller;
use App\Models\TimeOff;
use App\Services\Scheduling\WeekResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TimeOffController extends Controller
{
    use ResolvesStore;

    public function __construct(private readonly WeekResolver $weeks)
    {
    }

    /**
     * Multi-day leave is expanded to one entry per day: the grid is per-day and
     * shouldn't have to do calendar maths to shade a cell.
     */
    public function index(Request $request, string $storeId): JsonResponse
    {
        $store = $this->resolveStore($storeId);
        $settings = $store->settings();

        $weekStart = $this->weeks->normalizeWeekStart(
            $request->string('week_start')->toString() ?: now()->toDateString(),
            $this->weeks->weekStartDow($settings)
        );

        $weekEnd = $weekStart->addDays(6);

        $entries = TimeOff::query()
            ->where(fn ($query) => $query->where('store_id', $store->id)->orWhereNull('store_id'))
            ->overlappingDates($weekStart->toDateString(), $weekEnd->toDateString())
            ->get();

        $dtos = [];

        foreach ($entries as $entry) {
            $cursor = CarbonImmutable::parse(max($entry->start_date->toDateString(), $weekStart->toDateString()));
            $last = CarbonImmutable::parse(min($entry->end_date->toDateString(), $weekEnd->toDateString()));

            while ($cursor <= $last) {
                $dayIndex = $this->weeks->dayIndexFor($cursor, $weekStart);

                if ($dayIndex !== null) {
                    $dtos[] = [
                        'id' => "{$entry->id}-{$dayIndex}",
                        'time_off_id' => (string) $entry->id,
                        'employee_id' => (string) $entry->employee_id,
                        'day_index' => $dayIndex,
                        'date' => $cursor->toDateString(),
                        'type' => $entry->type,
                        'label' => $entry->label ?? ucfirst((string) $entry->type),
                        'status' => $entry->status,
                        'origin' => $entry->origin,
                    ];
                }

                $cursor = $cursor->addDay();
            }
        }

        return response()->json(['data' => $dtos]);
    }

    /**
     * Locally-entered time off. NOT written to Humanity: leave approval is a
     * workflow that lives there, and pushing an unapproved entry would create
     * a record nobody signed off on.
     */
    public function store(Request $request, string $storeId): JsonResponse
    {
        $store = $this->resolveStore($storeId);

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'min:1'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'type' => ['required', 'in:pto,vacation,sick,unpaid,other'],
            'label' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $entry = TimeOff::query()->create($validated + [
            'store_id' => $store->id,
            'all_day' => true,
            'status' => 'approved',
            'origin' => 'operations',
        ]);

        return response()->json(['data' => $entry], 201);
    }

    public function destroy(string $storeId, int $timeOffId): JsonResponse
    {
        $store = $this->resolveStore($storeId);

        $entry = TimeOff::query()->where('store_id', $store->id)->find($timeOffId);

        if ($entry === null) {
            throw new NotFoundHttpException("Time-off entry {$timeOffId} not found.");
        }

        // Humanity-sourced rows would just come back on the next sync, so
        // deleting one here is a lie the UI shouldn't tell.
        if ($entry->origin === 'humanity') {
            return response()->json([
                'message' => 'This time off comes from Humanity and must be withdrawn there.',
                'error' => ['code' => 'TIME_OFF_READ_ONLY', 'humanity_leave_id' => $entry->humanity_leave_id],
            ], 409);
        }

        $entry->delete();

        return response()->json(null, 204);
    }
}
