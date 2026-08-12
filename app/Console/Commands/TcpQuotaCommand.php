<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Store;
use App\Services\Tcp\TcpRateLimiter;
use Illuminate\Console\Command;

/**
 * What the TCP quota is being spent on, and whether the current shape of the
 * system fits inside it.
 *
 * 2500 requests per 24 hours is ~104/hour for the WHOLE service, so this is the
 * number to check before turning anything on or adding a scheduled job.
 */
class TcpQuotaCommand extends Command
{
    protected $signature = 'tcp:quota';

    protected $description = 'Show TCP request-quota usage and a projected daily cost';

    public function handle(TcpRateLimiter $limiter): int
    {
        $perDay = (int) config('tcp.rate_limit.per_day', 2500);
        $reserve = (int) config('tcp.rate_limit.reserve_per_day', 250);
        $used = $limiter->usedToday();

        $this->table(['metric', 'value'], [
            ['daily limit (TCP)', $perDay],
            ['reserved for interactive', $reserve],
            ['background budget', $perDay - $reserve],
            ['used today', $used],
            ['left for background', $limiter->remainingToday()],
            ['left including reserve', $limiter->remainingToday(interactive: true)],
        ]);

        $stores = Store::query()->count();
        $linked = Employee::query()->whereNotNull('tcp_employee_id')->count();

        // The change feed is account-wide and cached, so a quiet hour costs one
        // call no matter how many stores exist. That is the whole reason an
        // hourly sync is affordable.
        $quietPerHour = 1;
        $busyPerHour = 1 + (int) ceil(max(1, $linked) / (int) config('tcp.max_ids_per_request', 20));

        $this->newLine();
        $this->line('<info>Projected daily cost</info>');
        $this->table(['scenario', 'per hour', 'per day'], [
            ['sync, nothing changed', $quietPerHour, $quietPerHour * 24],
            ['sync, everyone changed', $busyPerHour, $busyPerHour * 24],
            ['punches (1 request each)', '—', 'volume dependent'],
        ]);

        $worstCase = $busyPerHour * 24;

        $this->newLine();
        $this->line(sprintf(
            '%d store(s), %d employee(s) linked to TCP.',
            $stores,
            $linked
        ));

        $headroom = $perDay - $reserve - $worstCase;

        if ($headroom < 0) {
            $this->error("Worst-case sync cost ({$worstCase}/day) exceeds the background budget.");
            $this->line('  Lengthen the schedule, or raise TCP_TIMECLOCK_LOOKBACK_DAYS less often.');

            return self::FAILURE;
        }

        $this->info("Worst-case sync leaves ~{$headroom} requests/day for punches and ad-hoc work.");

        // Each punch is one request; a store doing 40 punches a day across
        // several sites can still add up.
        $this->line(sprintf('  That is roughly %d punches/day, or %d per store.',
            $headroom,
            $stores > 0 ? intdiv($headroom, $stores) : $headroom
        ));

        return self::SUCCESS;
    }
}
