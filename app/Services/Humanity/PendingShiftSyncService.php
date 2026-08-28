<?php

namespace App\Services\Humanity;

use App\Models\Shift;
use App\Services\Humanity\Dto\HumanityShiftPayload;
use App\Services\Humanity\Exceptions\HumanityRateLimitException;
use App\Services\Scheduling\ShiftFingerprint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Carries shifts that a throttle stopped us writing to Humanity.
 *
 * Humanity publishes no rate limit, no window and no reset semantics — status
 * 91 says only "try again later", and the question has gone unanswered on
 * their developer forum for years. So the retry schedule cannot be derived; it
 * is a deliberate policy choice made against that uncertainty:
 *
 *   next attempt = min(now + 6h, the start of the next day), up to 4 attempts
 *
 * The daily boundary is there because a per-DAY quota is the most likely shape
 * of an undocumented limit that trips on bulk writes and then stays tripped;
 * if that guess is right, midnight is exactly when capacity returns. The
 * 6-hour cadence covers the case where it is a rolling window instead. Four
 * attempts spans a full day, after which something is wrong that waiting will
 * not fix, and the shift is parked for a human.
 *
 * Syncing means "make Humanity match this local row" rather than replaying a
 * recorded operation, so it is idempotent and needs no payload snapshot.
 */
class PendingShiftSyncService
{
    public function __construct(
        private readonly HumanityClientInterface $humanity,
        private readonly HumanitySyncLogger $syncLog,
        private readonly HumanityRateLimiter $limiter,
        private readonly ShiftFingerprint $fingerprint,
    ) {
    }

    public function maxAttempts(): int
    {
        return (int) config('humanity.sync_max_attempts', 4);
    }

    /**
     * Record that this shift is owed to Humanity.
     *
     * Called from the write path when — and only when — a throttle is what
     * stopped the write. Any other failure rejects the whole request instead,
     * so a shift never lands here because it was invalid.
     */
    public function markPending(Shift $shift, \Throwable $e): void
    {
        $shift->forceFill([
            'sync_status' => Shift::SYNC_PENDING,
            'sync_next_attempt_at' => $this->nextAttemptAt(),
            'sync_last_error' => $e->getMessage(),
        ])->save();

        Log::warning('Shift saved locally, owed to Humanity', [
            'shift_id' => $shift->id,
            'store_id' => $shift->store_id,
            'shift_date' => (string) $shift->shift_date?->toDateString(),
            'next_attempt_at' => (string) $shift->sync_next_attempt_at,
        ]);
    }

    /** min(now + retry hours, start of the next day) — see the class docblock. */
    public function nextAttemptAt(?CarbonImmutable $from = null): CarbonImmutable
    {
        $from ??= CarbonImmutable::now();

        $afterInterval = $from->addHours((int) config('humanity.sync_retry_hours', 6));
        $nextDay = $from->addDay()->startOfDay();

        return $afterInterval->lessThan($nextDay) ? $afterInterval : $nextDay;
    }

    /**
     * Push one pending shift to Humanity.
     *
     * Returns true when the shift is settled (synced, parked, or nothing left
     * to do). A throttle returns false and reschedules — the caller should
     * stop the pass rather than march the rest of the backlog into the same
     * wall.
     */
    public function syncOne(Shift $shift): bool
    {
        // Deleted before it ever reached Humanity: there is nothing upstream
        // to remove, so it is settled rather than owed.
        if ($shift->trashed() && $shift->humanity_shift_id === null) {
            $shift->forceFill([
                'sync_status' => Shift::SYNC_SYNCED,
                'sync_last_error' => null,
                'sync_next_attempt_at' => null,
            ])->save();

            return true;
        }

        $operation = match (true) {
            $shift->trashed() => 'delete',
            $shift->humanity_shift_id === null => 'create',
            default => 'update',
        };

        $log = $this->syncLog->begin(
            entityType: 'shift',
            operation: $operation,
            entityId: (int) $shift->id,
            storeId: (int) $shift->store_id,
            humanityId: $shift->humanity_shift_id,
        );

        try {
            $this->push($shift, $operation);
        } catch (HumanityRateLimitException $e) {
            $this->syncLog->failed($log, $e);
            $this->reschedule($shift, $e);

            return false;
        } catch (\Throwable $e) {
            $this->syncLog->failed($log, $e);

            // Not a throttle: waiting will not fix a bad mapping or a deleted
            // position, so park it now rather than burn a day of retries on
            // something only a human can resolve.
            $this->park($shift, $e->getMessage());

            return true;
        }

        $this->syncLog->succeeded($log, $shift->humanity_shift_id);

        return true;
    }

    private function push(Shift $shift, string $operation): void
    {
        if ($operation === 'delete') {
            $this->humanity->deleteShift((string) $shift->humanity_shift_id);

            $shift->forceFill([
                'sync_status' => Shift::SYNC_SYNCED,
                'sync_attempts' => 0,
                'sync_next_attempt_at' => null,
                'sync_last_error' => null,
            ])->save();

            return;
        }

        $employeeIds = $shift->assignments()
            ->whereNotNull('humanity_employee_id')
            ->pluck('humanity_employee_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        // Rebuilt from the local row rather than a stored snapshot: the row IS
        // the desired state, so a shift edited while it was pending syncs its
        // latest version instead of replaying a stale create.
        $startsLocal = CarbonImmutable::parse(
            $shift->shift_date->toDateString() . ' ' . $shift->start_time
        );

        $payload = new HumanityShiftPayload(
            locationId: (string) $shift->humanity_location_id,
            positionId: (string) $shift->humanity_position_id,
            startsLocal: $startsLocal,
            // From the stored duration, not end_time: an overnight shift ends
            // on the following date, and duration_minutes is the DST-correct
            // span the rest of the system already trusts.
            endsLocal: $startsLocal->addMinutes((int) $shift->duration_minutes),
            employeeIds: $employeeIds,
            title: $shift->label,
            note: $shift->note,
            slots: (int) $shift->slots,
        );

        $result = $operation === 'create'
            ? $this->humanity->createShift($payload)
            : $this->humanity->updateShift((string) $shift->humanity_shift_id, $payload);

        $shift->forceFill([
            'humanity_shift_id' => $result->shiftId,
            'sync_status' => Shift::SYNC_SYNCED,
            'sync_attempts' => 0,
            'sync_next_attempt_at' => null,
            'sync_last_error' => null,
        ])->save();

        $shift->forceFill([
            'humanity_hash' => $this->fingerprint->forLocalShift($shift->fresh(), $employeeIds),
        ])->save();
    }

    private function reschedule(Shift $shift, \Throwable $e): void
    {
        $attempts = (int) $shift->sync_attempts + 1;

        if ($attempts >= $this->maxAttempts()) {
            $this->park($shift, $e->getMessage(), $attempts);

            return;
        }

        $shift->forceFill([
            'sync_attempts' => $attempts,
            'sync_next_attempt_at' => $this->nextAttemptAt(),
            'sync_last_error' => $e->getMessage(),
        ])->save();
    }

    private function park(Shift $shift, string $error, ?int $attempts = null): void
    {
        $shift->forceFill([
            'sync_status' => Shift::SYNC_PARKED,
            'sync_attempts' => $attempts ?? (int) $shift->sync_attempts,
            'sync_next_attempt_at' => null,
            'sync_parked_at' => CarbonImmutable::now(),
            'sync_last_error' => $error,
        ])->save();

        // Loud on purpose: a parked shift is real and staffed locally but
        // invisible in Humanity, which is exactly the divergence this
        // integration exists to prevent. Nothing moves it without a human.
        Log::error('Shift parked — still not in Humanity after retries', [
            'shift_id' => $shift->id,
            'store_id' => $shift->store_id,
            'shift_date' => (string) $shift->shift_date?->toDateString(),
            'attempts' => $shift->sync_attempts,
            'error' => $error,
        ]);
    }

    public function inCooldown(): bool
    {
        return $this->limiter->inCooldown();
    }
}
