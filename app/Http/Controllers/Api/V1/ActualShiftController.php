<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesStore;
use App\Http\Controllers\Controller;
use App\Models\ActualShift;
use App\Models\ShiftAssignment;
use App\Services\Scheduling\ActualShiftRollup;
use App\Services\Scheduling\ActualShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ActualShiftController extends Controller
{
    use ResolvesStore;

    public function __construct(
        private readonly ActualShiftService $actuals,
        private readonly ActualShiftRollup $rollup,
    ) {
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
            // A punch logged against the wrong day could previously only be
            // deleted and re-entered.
            'shift_date' => ['sometimes', 'date_format:Y-m-d'],
            // Attaches an unlinked clock-in to the shift it was really for.
            // A LINK, not a re-entry: the punch keeps its own TCP segment, so
            // the employee's actual punch times and missed-punch flags survive.
            'shift_assignment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'start_time' => ['sometimes', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'end_time' => ['sometimes', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'shift_type' => ['sometimes', 'in:morning,evening,night,split,custom'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // The human verdict, and the only way back out of an absence
            // besides confirm-actual. `time_variance` is deliberately NOT
            // accepted: it is derived from the data on every write, and letting
            // a client assert it is how the two drift apart.
            'review_state' => ['sometimes', 'in:worked,absent'],
        ]);

        // array_key_exists, not `??`: these two are nullable, and sending an
        // explicit null means "clear it" — which `??` read as "leave it alone",
        // making a label impossible to remove once set.
        $keep = fn (string $field, $current) => array_key_exists($field, $validated)
            ? $validated[$field]
            : $current;

        // `id` is what tells upsert() WHICH entry this is. Without it, ad-hoc
        // coverage — which has no assignment to key on — was recreated instead
        // of amended, so every save left another copy behind.
        $updated = $this->actuals->upsert($store, [
            'id' => (int) $actual->id,
            'employee_id' => (int) $actual->employee_id,
            'shift_assignment_id' => $keep('shift_assignment_id', $actual->shift_assignment_id),
            'shift_date' => $validated['shift_date'] ?? $actual->shift_date->toDateString(),
            'start_time' => $validated['start_time'] ?? substr((string) $actual->start_time, 0, 5),
            // Falls back to the start when the shift is still open: a manager
            // amending an in-progress shift has to state an end, and there is
            // no previous one to keep.
            'end_time' => $validated['end_time']
                ?? substr((string) ($actual->end_time ?? $actual->start_time), 0, 5),
            'label' => $keep('label', $actual->label),
            'shift_type' => $validated['shift_type'] ?? $actual->shift_type,
            'note' => $keep('note', $actual->manager_note),
            'review_state' => $validated['review_state'] ?? null,
        ], $request->user()?->id);

        return response()->json(['data' => $this->actuals->present($updated)]);
    }

    public function absent(Request $request, string $storeId, int $actualId): JsonResponse
    {
        $store = $this->resolveStore($storeId);
        $actual = $this->findActual((int) $store->id, $actualId);

        // Through the service, not a direct update: a no-show has to remove the
        // worked time from TCP as well, or the hours are still paid.
        $updated = $this->actuals->markAbsentEntry(
            $store,
            $actual,
            $request->input('note', $actual->manager_note),
            $request->user()?->id,
        );

        return response()->json(['data' => $this->actuals->present($updated)]);
    }

    /**
     * Two shifts were really one. Fold the punches together and pin it.
     *
     * Nothing is sent to TCP: which segments form a shift is our concept, and
     * TCP has nowhere to put it. The hours are unchanged — they are the same
     * punches, counted once.
     */
    public function merge(Request $request, string $storeId, int $actualId): JsonResponse
    {
        $validated = $request->validate([
            'actual_shift_ids' => ['required', 'array', 'min:1'],
            'actual_shift_ids.*' => ['integer'],
        ]);

        $store = $this->resolveStore($storeId);
        $target = $this->findActual((int) $store->id, $actualId);

        $merged = $this->rollup->merge($store, $target, $validated['actual_shift_ids']);

        return response()->json(['data' => $this->actuals->present($merged)]);
    }

    /** One shift was really two. Move the named punches out into their own. */
    public function split(Request $request, string $storeId, int $actualId): JsonResponse
    {
        $validated = $request->validate([
            'segment_ids' => ['required', 'array', 'min:1'],
            'segment_ids.*' => ['integer'],
        ]);

        $store = $this->resolveStore($storeId);
        $source = $this->findActual((int) $store->id, $actualId);

        $created = $this->rollup->split($store, $source, $validated['segment_ids']);

        return response()->json(['data' => $this->actuals->present($created)], 201);
    }

    /** The one-click "worked as planned" from the review grid. */
    public function confirmPlanned(Request $request, string $storeId, int $assignmentId): JsonResponse
    {
        $store = $this->resolveStore($storeId);
        $assignment = $this->findAssignment($store->id, $assignmentId);

        $actual = $this->actuals->confirmPlanned($store, $assignment, $request->user()?->id);

        return response()->json(['data' => $this->actuals->present($actual)], 201);
    }

    /**
     * The one-click "they never turned up", straight from the planned shift.
     *
     * One call, and it writes the absence directly. The client used to create
     * an actual from the plan and then flip it absent, which meant the first
     * request briefly recorded them as having worked the shift — and, now that
     * actuals write through to TCP, created a work segment only to delete it.
     */
    public function absentPlanned(Request $request, string $storeId, int $assignmentId): JsonResponse
    {
        $store = $this->resolveStore($storeId);
        $assignment = $this->findAssignment($store->id, $assignmentId);

        $actual = $this->actuals->markAbsent(
            $store,
            $assignment,
            $request->input('note'),
            $request->user()?->id,
        );

        return response()->json(['data' => $this->actuals->present($actual)], 201);
    }

    private function findAssignment(int $storeId, int $assignmentId): ShiftAssignment
    {
        $assignment = ShiftAssignment::query()
            ->with('shift')
            ->whereHas('shift', fn ($query) => $query->forStore($storeId))
            ->find($assignmentId);

        if ($assignment === null) {
            throw new NotFoundHttpException("Shift assignment {$assignmentId} not found.");
        }

        return $assignment;
    }

    public function destroy(string $storeId, int $actualId): JsonResponse
    {
        $store = $this->resolveStore($storeId);

        // Removes the TCP segment too — see ActualShiftService::delete().
        $this->actuals->delete($store, $this->findActual((int) $store->id, $actualId));

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
