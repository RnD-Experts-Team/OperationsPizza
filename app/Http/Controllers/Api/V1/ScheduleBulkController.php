<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesStore;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessBulkOperationJob;
use App\Models\ScheduleBulkOperation;
use App\Models\ScheduleTemplate;
use App\Services\Scheduling\BulkOperationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * All bulk endpoints return 202 + a batch id. A copy-week is ~70 Humanity
 * calls against an undocumented rate limit — it cannot be a synchronous request.
 */
class ScheduleBulkController extends Controller
{
    use ResolvesStore;

    public function __construct(private readonly BulkOperationService $bulk)
    {
    }

    public function copyWeek(Request $request, string $storeId): JsonResponse
    {
        $store = $this->resolveStore($storeId);

        $validated = $request->validate([
            'source_week_start' => ['required', 'date_format:Y-m-d'],
            'target_week_start' => ['required', 'date_format:Y-m-d'],
            'mode' => ['nullable', 'in:replace,merge'],
        ]);

        $operation = $this->bulk->copyWeek(
            $store,
            $validated['source_week_start'],
            $validated['target_week_start'],
            $validated['mode'] ?? 'replace',
            $request->user()?->id
        );

        return response()->json(['data' => $this->bulk->present($operation)], 202);
    }

    public function applyTemplate(Request $request, string $storeId): JsonResponse
    {
        $store = $this->resolveStore($storeId);

        $validated = $request->validate([
            'template_id' => ['required', 'integer', 'min:1'],
            'week_start' => ['required', 'date_format:Y-m-d'],
            'mode' => ['nullable', 'in:replace,merge'],
        ]);

        $template = ScheduleTemplate::query()
            ->where('store_id', $store->id)
            ->find($validated['template_id']);

        if ($template === null) {
            throw new NotFoundHttpException("Template {$validated['template_id']} not found.");
        }

        $operation = $this->bulk->applyTemplate(
            $store,
            $template,
            $validated['week_start'],
            $validated['mode'] ?? 'replace',
            $request->user()?->id
        );

        return response()->json(['data' => $this->bulk->present($operation)], 202);
    }

    public function createShifts(Request $request, string $storeId): JsonResponse
    {
        $store = $this->resolveStore($storeId);

        $validated = $request->validate([
            'week_start' => ['required', 'date_format:Y-m-d'],
            'mode' => ['nullable', 'in:replace,merge'],
            'shifts' => ['required', 'array', 'min:1', 'max:500'],
            'shifts.*.employee_id' => ['required', 'integer', 'min:1'],
            'shifts.*.day_index' => ['required', 'integer', 'min:0', 'max:6'],
            'shifts.*.start_time' => ['required', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'shifts.*.end_time' => ['required', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'shifts.*.label' => ['nullable', 'string', 'max:120'],
            'shifts.*.shift_type' => ['nullable', 'in:morning,evening,night,split,custom'],
            'shifts.*.note' => ['nullable', 'string', 'max:2000'],
            'shifts.*.position_label' => ['nullable', 'string', 'max:190'],
        ]);

        $operation = $this->bulk->createShifts(
            $store,
            $validated['week_start'],
            $validated['shifts'],
            $validated['mode'] ?? 'merge',
            $request->user()?->id
        );

        return response()->json(['data' => $this->bulk->present($operation)], 202);
    }

    public function clearWeek(Request $request, string $storeId): JsonResponse
    {
        $store = $this->resolveStore($storeId);

        $validated = $request->validate([
            'week_start' => ['required', 'date_format:Y-m-d'],
            // Wiping a week deletes real shifts in Humanity that employees may
            // already be working from.
            'confirm' => ['required', 'accepted'],
        ]);

        $operation = $this->bulk->clearWeek($store, $validated['week_start'], $request->user()?->id);

        return response()->json(['data' => $this->bulk->present($operation)], 202);
    }

    public function show(string $storeId, string $batchId): JsonResponse
    {
        $store = $this->resolveStore($storeId);

        return response()->json(['data' => $this->bulk->present($this->findOperation((int) $store->id, $batchId))]);
    }

    public function retryFailed(string $storeId, string $batchId): JsonResponse
    {
        $store = $this->resolveStore($storeId);
        $operation = $this->findOperation((int) $store->id, $batchId);

        $failedCount = $operation->items()->where('status', 'failed')->count();

        // Items left `pending` on a failed operation are unfinished work too —
        // that is exactly what a throttle-killed run leaves behind. Counting
        // only `failed` here is what used to make Retry a silent no-op on the
        // one case that most needed it.
        $unfinishedCount = $operation->items()->whereIn('status', ['pending', 'processing'])->count();

        if ($failedCount === 0 && $unfinishedCount === 0) {
            return response()->json(['data' => $this->bulk->present($operation)]);
        }

        // Reset the failures, and roll the counter back by the same amount so
        // progress stays truthful on the second pass. Pending items are
        // already in the right state and just need a job to pick them up.
        $operation->items()->where('status', 'failed')->update(['status' => 'pending']);

        $operation->update([
            'status' => ScheduleBulkOperation::STATUS_QUEUED,
            'failed_items' => max(0, (int) $operation->failed_items - $failedCount),
            'finished_at' => null,
        ]);

        ProcessBulkOperationJob::dispatch($operation->id);

        return response()->json(['data' => $this->bulk->present($operation->fresh())], 202);
    }

    private function findOperation(int $storeId, string $batchId): ScheduleBulkOperation
    {
        $operation = ScheduleBulkOperation::query()->where('store_id', $storeId)->find($batchId);

        if ($operation === null) {
            throw new NotFoundHttpException("Bulk operation {$batchId} not found.");
        }

        return $operation;
    }
}
