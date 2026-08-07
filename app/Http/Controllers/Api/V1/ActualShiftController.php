<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesStore;
use App\Http\Controllers\Controller;
use App\Models\ActualShift;
use App\Models\ShiftAssignment;
use App\Services\Scheduling\ActualShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ActualShiftController extends Controller
{
    use ResolvesStore;

    public function __construct(private readonly ActualShiftService $actuals)
    {
    }

    public function store(Request $request, string $storeId): JsonResponse
    {
        $store = $this->resolveStore($storeId);

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'min:1'],
            'shift_assignment_id' => ['nullable', 'integer', 'min:1'],
            'shift_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'end_time' => ['required', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'label' => ['nullable', 'string', 'max:120'],
            'shift_type' => ['nullable', 'in:morning,evening,night,split,custom'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        // status is derived by the service, never taken from the client.
        $actual = $this->actuals->upsert($store, $validated, $request->user()?->id);

        return response()->json(['data' => $this->actuals->present($actual)], 201);
    }

    public function update(Request $request, string $storeId, int $actualId): JsonResponse
    {
        $store = $this->resolveStore($storeId);
        $actual = $this->findActual((int) $store->id, $actualId);

        $validated = $request->validate([
            'start_time' => ['sometimes', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'end_time' => ['sometimes', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'shift_type' => ['sometimes', 'in:morning,evening,night,split,custom'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $updated = $this->actuals->upsert($store, [
            'employee_id' => (int) $actual->employee_id,
            'shift_assignment_id' => $actual->shift_assignment_id,
            'shift_date' => $actual->shift_date->toDateString(),
            'start_time' => $validated['start_time'] ?? substr((string) $actual->start_time, 0, 5),
            'end_time' => $validated['end_time'] ?? substr((string) $actual->end_time, 0, 5),
            'label' => $validated['label'] ?? $actual->label,
            'shift_type' => $validated['shift_type'] ?? $actual->shift_type,
            'note' => $validated['note'] ?? $actual->note,
        ], $request->user()?->id);

        return response()->json(['data' => $this->actuals->present($updated)]);
    }

    public function absent(Request $request, string $storeId, int $actualId): JsonResponse
    {
        $store = $this->resolveStore($storeId);
        $actual = $this->findActual((int) $store->id, $actualId);

        $actual->update([
            'status' => ActualShift::STATUS_ABSENT,
            'note' => $request->input('note', $actual->note),
            'reviewed_by_user_id' => $request->user()?->id,
            'reviewed_at' => now(),
        ]);

        return response()->json(['data' => $this->actuals->present($actual->fresh())]);
    }

    /** The one-click "worked as planned" from the review grid. */
    public function confirmPlanned(Request $request, string $storeId, int $assignmentId): JsonResponse
    {
        $store = $this->resolveStore($storeId);

        $assignment = ShiftAssignment::query()
            ->with('shift')
            ->whereHas('shift', fn ($query) => $query->forStore((int) $store->id))
            ->find($assignmentId);

        if ($assignment === null) {
            throw new NotFoundHttpException("Shift assignment {$assignmentId} not found.");
        }

        $actual = $this->actuals->confirmPlanned($store, $assignment, $request->user()?->id);

        return response()->json(['data' => $this->actuals->present($actual)], 201);
    }

    public function destroy(string $storeId, int $actualId): JsonResponse
    {
        $store = $this->resolveStore($storeId);

        $this->findActual((int) $store->id, $actualId)->delete();

        return response()->json(null, 204);
    }

    private function findActual(int $storeId, int $actualId): ActualShift
    {
        $actual = ActualShift::query()->where('store_id', $storeId)->find($actualId);

        if ($actual === null) {
            throw new NotFoundHttpException("Actual shift {$actualId} not found.");
        }

        return $actual;
    }
}
