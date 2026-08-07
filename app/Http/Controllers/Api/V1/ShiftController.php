<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ShiftStoreRequest;
use App\Http\Requests\Api\V1\ShiftUpdateRequest;
use App\Models\Shift;
use App\Services\Scheduling\ShiftQueryService;
use App\Services\Scheduling\ShiftWriteService;
use App\Services\Scheduling\WeekResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ShiftController extends Controller
{
    use ResolvesStore;

    public function __construct(
        private readonly ShiftWriteService $writer,
        private readonly ShiftQueryService $query,
        private readonly WeekResolver $weeks,
    ) {
    }

    public function store(ShiftStoreRequest $request, string $storeId): JsonResponse
    {
        $store = $this->resolveStore($storeId);

        $shift = $this->writer->create($store, $request->validated(), $request);

        return response()->json(['data' => $this->present($shift, $store)], 201);
    }

    public function show(string $storeId, int $shiftId): JsonResponse
    {
        $store = $this->resolveStore($storeId);

        return response()->json(['data' => $this->present($this->findShift($store->id, $shiftId), $store)]);
    }

    /** POST, not PUT — the house convention across these services. */
    public function update(ShiftUpdateRequest $request, string $storeId, int $shiftId): JsonResponse
    {
        $store = $this->resolveStore($storeId);
        $shift = $this->findShift($store->id, $shiftId);

        $updated = $this->writer->update($store, $shift, $request->validated(), $request);

        return response()->json(['data' => $this->present($updated, $store)]);
    }

    public function destroy(Request $request, string $storeId, int $shiftId): JsonResponse
    {
        $store = $this->resolveStore($storeId);
        $shift = $this->findShift($store->id, $shiftId);

        // Employees may already have been notified by Humanity, so removing a
        // published shift takes an explicit acknowledgement.
        if ($shift->is_published && !$request->boolean('confirm')) {
            return response()->json([
                'message' => 'This shift is published and employees may already have seen it.',
                'error' => ['code' => 'SHIFT_PUBLISHED', 'requires' => 'confirm=true'],
            ], 409);
        }

        $this->writer->delete($store, $shift, $request);

        return response()->json(null, 204);
    }

    private function findShift(int $storeId, int $shiftId): Shift
    {
        $shift = Shift::query()->forStore($storeId)->with('assignments')->find($shiftId);

        if ($shift === null) {
            throw new NotFoundHttpException("Shift {$shiftId} not found in this store.");
        }

        return $shift;
    }

    /** One DTO per assignment, matching the week endpoint. */
    private function present(Shift $shift, $store): array
    {
        $settings = $store->settings();
        $weekStart = $this->weeks->weekStartFor(
            CarbonImmutable::parse($shift->shift_date),
            $this->weeks->weekStartDow($settings)
        );

        $assignments = $shift->assignments()->with('shift')->get();

        $dtos = $this->query->toDtos($assignments, $weekStart);

        return $dtos[0] ?? [];
    }
}
