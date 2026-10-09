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
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Runs a bulk operation ONE DAY AT A TIME on the rate-limited `humanity` queue,
 * yielding between days.
 *
 * Deliberately serial rather than a fan-out of per-item jobs: Humanity's rate
 * limit is unpublished, account-wide and trips on bursts, so a controlled walk
 * is safer than N workers racing.
 *
 * The day slicing is what makes a busy publish day survivable. With 38 stores
 * building next week at once, processing each store's whole week before
 * starting the next means store 38 waits behind 37 complete copy-weeks — and
 * if the account throttles meanwhile, early stores have a full schedule while
 * late ones have nothing. Yielding after each day puts the job at the back of
 * the queue, so every store gains Monday before any store gains Tuesday. A
 * throttle then costs everyone their LAST days rather than costing a few
 * stores everything.
 *
 * Yielding re-dispatches a FRESH job rather than calling release(): a
 * cooperative yield is not a failure and must not spend the retry budget.
 */
class ProcessBulkOperationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * High, because a throttled release costs one. The real bound is
     * retryUntil() below — $tries = 3 previously meant an operation survived
     * only TWO throttles, whether it had 5 items or 500.
     */
    public int $tries = 25;

    public function __construct(public string $operationId)
    {
        $this->onQueue('humanity');
    }

    /**
     * A day slice is short, so an hour is generous. Bounding by time rather
     * than by attempts is what lets the budget scale with the work.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHour();
    }

    /**
     * The database queue reclaims a job after retry_after (90s) and lets a
     * second worker take it; two workers on one operation duplicate Humanity
     * shifts, which needs manual cleanup to undo. Day slices are short enough
     * that this should never fire, but the failure mode justifies the guard.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->operationId))->releaseAfter(15)->expireAfter(300)];
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

        $slice = $this->nextDaySlice($operation);

        if ($slice->isEmpty()) {
            $this->finish($operation);

            return;
        }

        $processed = 0;

        foreach ($slice as $item) {
            try {
                $this->processItem($writer, $store, $item);

                $item->update(['status' => 'succeeded', 'error_code' => null, 'error_message' => null]);
                $operation->increment('succeeded_items');
                $processed++;
            } catch (HumanityRateLimitException $e) {
                // A safety net rather than the main path: ShiftWriteService
                // now absorbs a throttle by saving the shift locally as
                // pending-sync, so the item succeeds and the sweep carries it
                // to Humanity later. This still catches a throttle raised
                // anywhere else in the write.
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
                $processed++;
            }

            // Pacing is no longer a per-process sleep here: HumanityRateLimiter
            // gates every call account-wide, so worker count no longer changes
            // the real request rate.
        }

        $operation->refresh();

        if ($operation->items()->whereIn('status', ['pending', 'processing'])->exists()) {
            // A slice that resolved nothing would re-dispatch forever. Fail
            // loudly instead — a silent infinite loop on the shared Humanity
            // queue would starve every other store.
            if ($processed === 0) {
                $operation->update([
                    'status' => ScheduleBulkOperation::STATUS_FAILED,
                    'error' => 'Bulk operation made no progress on a day slice; stopping to avoid a loop.',
                    'finished_at' => now(),
                ]);

                Log::error('Bulk operation stalled without progress', ['operation_id' => $operation->id]);

                return;
            }

            // Yield: back of the queue, so the next store's day goes before
            // this store's next day. A fresh dispatch, not release() — see the
            // class docblock.
            self::dispatch($this->operationId);

            return;
        }

        $this->finish($operation);
    }

    /**
     * The earliest unfinished day's items.
     *
     * `sequence` already encodes day order (BulkOperationService groups by day
     * and puts each day's deletes before its creates), so the first remaining
     * item identifies the day; the slice is every remaining item sharing its
     * date. Items with no date — a delete whose shift row has since gone —
     * form their own final slice.
     *
     * @return \Illuminate\Support\Collection<int, ScheduleBulkOperationItem>
     */
    private function nextDaySlice(ScheduleBulkOperation $operation): Collection
    {
        $remaining = $operation->items()->whereIn('status', ['pending', 'processing'])->orderBy('sequence');

        $first = (clone $remaining)->first();

        if ($first === null) {
            return collect();
        }

        $day = $first->shift_date;

        // whereDate, not a plain equality: the `date` cast writes a full
        // datetime, which MySQL truncates into a DATE column and SQLite stores
        // verbatim. Comparing the raw strings matches on one driver and
        // silently matches nothing on the other.
        return $remaining
            ->when(
                $day === null,
                fn ($query) => $query->whereNull('shift_date'),
                fn ($query) => $query->whereDate('shift_date', $day->toDateString()),
            )
            ->get();
    }

    private function finish(ScheduleBulkOperation $operation): void
    {
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

    public function failed(\Throwable $e): void
    {
        $operation = ScheduleBulkOperation::query()->find($this->operationId);

        if ($operation === null) {
            return;
        }

        // Leftover items were `pending`, which used to strand the operation:
        // retryFailed() counts only `failed` items, so it saw zero, returned
        // 200 and re-dispatched nothing. A manager saw a failed week and a
        // Retry button that silently did nothing. Marking them failed both
        // tells the truth and makes the operation resumable.
        $stranded = $operation->items()->whereIn('status', ['pending', 'processing'])->get();

        foreach ($stranded as $item) {
            $item->update([
                'status' => 'failed',
                'error_code' => $this->errorCode($e),
                'error_message' => $e->getMessage(),
            ]);
        }

        $operation->update([
            'status' => ScheduleBulkOperation::STATUS_FAILED,
            'error' => $e->getMessage(),
            'failed_items' => (int) $operation->failed_items + $stranded->count(),
            'finished_at' => now(),
        ]);

        Log::error('Bulk operation failed with work outstanding', [
            'operation_id' => $operation->id,
            'stranded_items' => $stranded->count(),
            'error' => $e->getMessage(),
        ]);
    }
}
