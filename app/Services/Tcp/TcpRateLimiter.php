<?php

namespace App\Services\Tcp;

use App\Services\Tcp\Exceptions\TcpRateLimitException;
use Illuminate\Support\Facades\Cache;

/**
 * Client-side budget for TCP's two limits: 60/minute and 2500 per 24 hours.
 *
 * The daily cap is what actually shapes this integration. 2500 calls spread
 * over a day is ~104/hour for the ENTIRE service — every store, every
 * background job, every manager clicking a button. A single Humanity copy-week
 * is ~70 calls; the same pattern against TCP would eat a third of the day.
 *
 * So we refuse locally rather than collect a 429. Three reasons:
 *   - a 429 may carry a cooldown penalty, making the next hour worse;
 *   - refusing lets a caller degrade gracefully (skip a sync, queue a retry)
 *     instead of failing mid-write;
 *   - a reserve keeps an interactive clock-in from being starved by a
 *     background sync that happened to run first.
 *
 * Counters live in the cache, which means they are per-cache-store rather than
 * globally exact. That is deliberate: the reserve absorbs the drift, and a
 * shared Redis store makes it accurate across workers.
 */
class TcpRateLimiter
{
    private const MINUTE_KEY = 'tcp:rate:minute';
    private const DAY_KEY = 'tcp:rate:day';

    /**
     * Consume one request slot, or throw.
     *
     * @param  bool  $interactive  Interactive traffic (a manager clocking
     *                             someone in) may dip into the reserve that
     *                             background syncs must leave alone.
     */
    public function hit(bool $interactive = false): void
    {
        $config = (array) config('tcp.rate_limit');

        $perMinute = (int) ($config['per_minute'] ?? 60);
        $perDay = (int) ($config['per_day'] ?? 2500);
        $reserve = (int) ($config['reserve_per_day'] ?? 0);

        $dayUsed = (int) Cache::get($this->dayKey(), 0);
        $dayBudget = $interactive ? $perDay : max(0, $perDay - $reserve);

        if ($dayUsed >= $dayBudget) {
            throw new TcpRateLimitException(
                $interactive
                    ? "TCP daily quota exhausted ({$dayUsed}/{$perDay})."
                    : "TCP daily budget for background work exhausted ({$dayUsed}/{$dayBudget}; {$reserve} held in reserve for interactive traffic).",
                retryAfterSeconds: $this->secondsUntilTomorrow(),
                isDailyCap: true,
            );
        }

        $minuteUsed = (int) Cache::get($this->minuteKey(), 0);

        if ($minuteUsed >= $perMinute) {
            throw new TcpRateLimitException(
                "TCP per-minute limit reached ({$minuteUsed}/{$perMinute}).",
                retryAfterSeconds: 60 - (int) date('s'),
            );
        }

        // Written before the call, so an in-flight request already counts. A
        // crash mid-request must not hand the budget back.
        Cache::put($this->minuteKey(), $minuteUsed + 1, 120);
        Cache::put($this->dayKey(), $dayUsed + 1, $this->secondsUntilTomorrow() + 3600);
    }

    /** Whether a background job of this size can run without touching the reserve. */
    public function canAfford(int $requests, bool $interactive = false): bool
    {
        $config = (array) config('tcp.rate_limit');

        $perDay = (int) ($config['per_day'] ?? 2500);
        $reserve = (int) ($config['reserve_per_day'] ?? 0);
        $budget = $interactive ? $perDay : max(0, $perDay - $reserve);

        return ((int) Cache::get($this->dayKey(), 0)) + $requests <= $budget;
    }

    public function remainingToday(bool $interactive = false): int
    {
        $config = (array) config('tcp.rate_limit');

        $perDay = (int) ($config['per_day'] ?? 2500);
        $reserve = (int) ($config['reserve_per_day'] ?? 0);
        $budget = $interactive ? $perDay : max(0, $perDay - $reserve);

        return max(0, $budget - (int) Cache::get($this->dayKey(), 0));
    }

    public function usedToday(): int
    {
        return (int) Cache::get($this->dayKey(), 0);
    }

    /** A 429 from TCP means our local count drifted — resync to the ceiling. */
    public function recordRemoteRejection(): void
    {
        Cache::put($this->minuteKey(), (int) config('tcp.rate_limit.per_minute', 60), 120);
    }

    private function minuteKey(): string
    {
        return self::MINUTE_KEY . ':' . date('YmdHi');
    }

    /**
     * TCP's window is rolling; a calendar-day key is an approximation that errs
     * toward spending less, which is the safe direction.
     */
    private function dayKey(): string
    {
        return self::DAY_KEY . ':' . date('Ymd');
    }

    private function secondsUntilTomorrow(): int
    {
        return max(60, strtotime('tomorrow') - time());
    }
}
