<?php

namespace App\Jobs;

use App\Models\ScheduleBulkOperation;
use App\Models\ScheduleBulkOperationItem;
use App\Models\Shift;
use App\Models\Store;
use App\Services\Humanity\Exceptions\HumanityException;
use App\Services\Humanity\Exceptions\HumanityRateLimitException;
use App\Services\Scheduling\Exceptions\SchedulingException;
use App\Services\Scheduling\ShiftWriteService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs a whole bulk operation, item by item, on the rate-limited `humanity`
 * queue.
 *
 * Deliberately serial rather than a fan-out of per-item jobs: Humanity's rate
 * limit is unpublished and trips on bursts, so one worker walking the list at a
 * controlled pace is safer than N workers racing. A throttle response pauses
 * the whole operation instead of burning every remaining item.
 */
class ProcessBulkOperationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public string $operationId)
    {
        $this->onQueue('humanity');
    }

    public function handle(ShiftWriteService $writer): void
    {
        $operation = ScheduleBulkOperation::query()->with('items')->find($this->operationId);

        if ($operation === null || in_array($operation->status, [
            ScheduleBulkOperation::STATUS_COMPLETED,
            ScheduleBulkOperation::STATUS_COMPLETED_WITH_ERRORS,
        ], true)) {
            return;
        }

        $store = Store::query()->find($operation->store_id);

        if ($store === null) {
            $operation->update([
                'status' => ScheduleBulkOperation::STATUS_FAILED,
                'error' => 'Store no longer exists.',
                'finished_at' => now(),
            ]);

            return;
        }

        $operation->update([
            'status' => ScheduleBulkOperation::STATUS_PROCESSING,
            'started_at' => $operation->started_at ?? now(),
        ]);

        $delayMicroseconds = $this->throttleMicroseconds();

        foreach ($operation->items()->whereIn('status', ['pending', 'processing'])->orderBy('sequence')->get() as $item) {
            try {
                $this->processItem($writer, $store, $item);

                $item->update(['status' => 'succeeded', 'error_code' => null, 'error_message' => null]);
                $operation->increment('succeeded_items');
            } catch (HumanityRateLimitException $e) {
                // Pause the whole run rather than marching the remaining items
                // into the same wall. The retry picks up where this left off,
                // because completed items are already marked succeeded.
                $item->update(['status' => 'pending', 'attempts' => $item->attempts + 1]);

                Log::warning('Bulk operation paused by Humanity throttle', [
                    'operation_id' => $operation->id,
                    'remaining' => $operation->items()->where('status', 'pending')->count(),
                ]);

                $this->release($e->retryAfterSeconds());

                return;
            } catch (\Throwable $e) {
                $item->update([
                    'status' => 'failed',
                    'attempts' => $item->attempts + 1,
                    'error_code' => $this->errorCode($e),
                    'error_message' => $e->getMessage(),
                ]);

                $operation->increment('failed_items');
            }

            if ($delayMicroseconds > 0) {
                usleep($delayMicroseconds);
            }
        }

        $operation->refresh();

        $operation->update([
            'status' => $operation->failed_items > 0
                ? ScheduleBulkOperation::STATUS_COMPLETED_WITH_ERRORS
                : ScheduleBulkOperation::STATUS_COMPLETED,
            'finished_at' => now(),
        ]);
    }

    private function processItem(ShiftWriteService $writer, Store $store, ScheduleBulkOperationItem $item): void
    {
        $item->update(['status' => 'processing']);

        if ($item->action === 'delete') {
            $shift = Shift::query()->forStore((int) $store->id)->find($item->shift_id);

            // Already gone (a concurrent edit, or a retry) — not a failure.
            if ($shift === null) {
                return;
            }

            $writer->delete($store, $shift);

            return;
        }

        // force: the copied week was already accepted once; re-asking the
        // manager about every conflict would make the feature unusable.
        $writer->create($store, ($item->payload ?? []) + ['force' => true]);
    }

    private function errorCode(\Throwable $e): string
    {
        return match (true) {
            $e instanceof SchedulingException => $e->errorCode,
            $e instanceof HumanityException => $e->errorCode(),
            default => class_basename($e),
        };
    }

    private function throttleMicroseconds(): int
    {
        $rps = (float) config('humanity.requests_per_second', 3);

        return $rps > 0 ? (int) round(1_000_000 / $rps) : 0;
    }

    public function failed(\Throwable $e): void
    {
        ScheduleBulkOperation::query()->where('id', $this->operationId)->update([
            'status' => ScheduleBulkOperation::STATUS_FAILED,
            'error' => $e->getMessage(),
            'finished_at' => now(),
        ]);
    }
}
