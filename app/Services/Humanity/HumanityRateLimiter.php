<?php

namespace App\Services\Humanity;

use App\Services\Humanity\Exceptions\HumanityRateLimitException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Account-wide pacing for Humanity, and a shared cooldown once it throttles.
 *
 * Unlike TCP — whose 60/minute and 2500/day are documented and modelled exactly
 * by TcpRateLimiter — Humanity publishes NO limit at all. Its docs define
 * status 91 as "Throttle exceeded... Try again later" and stop there: no
 * number, no window, no headers, and the question has sat unanswered on their
 * developer forum for years. Whether master/child group accounts share one
 * bucket is also unanswered, so this treats the limit as ACCOUNT-WIDE across
 * every store.
 *
 * Two jobs, then:
 *
 * 1. Pace outbound calls to a self-imposed requests_per_second. This used to be
 *    a `usleep` inside the bulk job, which meant the real request rate was
 *    multiplied by the number of queue workers — the pacing silently did
 *    nothing the moment anyone scaled workers. Counting in the cache makes it
 *    shared.
 *
 * 2. Hold a cooldown after a 91. Without it, every worker rediscovers the limit
 *    independently and keeps hammering an account that has already said stop.
 *    During cooldown calls are refused LOCALLY, costing Humanity nothing.
 *
 * Counters live in the cache, so they are per-cache-store rather than globally
 * exact — the same tradeoff TcpRateLimiter documents. A shared Redis store
 * makes it accurate across workers; the drift is acceptable because the
 * cooldown, not the counter, is what protects us after a real throttle.
 */
class HumanityRateLimiter
{
    private const SECOND_KEY = 'humanity:rate:second';
    private const MINUTE_KEY = 'humanity:rate:minute';
    private const HOUR_KEY = 'humanity:rate:hour';
    private const COOLDOWN_KEY = 'humanity:rate:cooldown';

    /**
     * Claim a slot before calling Humanity.
     *
     * Throws rather than blocking when the account is in cooldown: callers
     * decide what that means for them. A shift write turns it into a local
     * save plus a queued sync; the sweep skips the pass entirely.
     */
    public function hit(): void
    {
        if ($this->inCooldown()) {
            throw new HumanityRateLimitException(
                'Humanity is in a local throttle cooldown; not calling it.',
                HumanityResponse::THROTTLED,
                200,
            );
        }

        $perSecond = (int) config('humanity.requests_per_second', 3);

        if ($perSecond > 0) {
            // Spin over second buckets rather than sleeping a fixed interval:
            // with several workers the fixed sleep paced each process, not the
            // account. Bounded so a wedged counter cannot hang a worker.
            for ($waited = 0; $waited < $perSecond * 2 + 2; $waited++) {
                $used = (int) Cache::get($this->secondKey(), 0);

                if ($used < $perSecond) {
                    break;
                }

                usleep(1_000_000 - (int) (microtime(true) * 1_000_000) % 1_000_000);
            }
        }

        // Incremented BEFORE the call so an in-flight request already counts;
        // a crash mid-request must not hand the slot back.
        $this->bump($this->secondKey(), 5);
        $this->bump(self::MINUTE_KEY . ':' . date('YmdHi'), 180);
        $this->bump(self::HOUR_KEY . ':' . date('YmdH'), 7200);
    }

    /**
     * Humanity said 91. Stop the whole account for a cooldown.
     *
     * The window is a guess — it has to be, since the real one is undocumented
     * — so it is deliberately short. Being wrong short costs one more refused
     * call; being wrong long stalls a schedule nobody can publish.
     */
    public function recordThrottle(): void
    {
        $seconds = (int) config('humanity.throttle_backoff_seconds', 30);

        Cache::put(self::COOLDOWN_KEY, CarbonImmutable::now()->addSeconds($seconds)->toIso8601String(), $seconds);
    }

    public function inCooldown(): bool
    {
        return Cache::get(self::COOLDOWN_KEY) !== null;
    }

    public function cooldownEndsAt(): ?CarbonImmutable
    {
        $value = Cache::get(self::COOLDOWN_KEY);

        return $value === null ? null : CarbonImmutable::parse($value);
    }

    /**
     * Calls made in the trailing minute and hour.
     *
     * This is the number worth having when a 91 arrives: it is the only way
     * this service will ever infer where the real ceiling sits, since Humanity
     * documents nothing and a 91 carries no hint.
     *
     * @return array{minute:int, hour:int}
     */
    public function recentUsage(): array
    {
        return [
            'minute' => (int) Cache::get(self::MINUTE_KEY . ':' . date('YmdHi'), 0),
            'hour' => (int) Cache::get(self::HOUR_KEY . ':' . date('YmdH'), 0),
        ];
    }

    private function bump(string $key, int $ttlSeconds): void
    {
        Cache::put($key, ((int) Cache::get($key, 0)) + 1, $ttlSeconds);
    }

    private function secondKey(): string
    {
        return self::SECOND_KEY . ':' . date('YmdHis');
    }
}
