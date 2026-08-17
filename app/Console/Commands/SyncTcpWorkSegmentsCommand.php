<?php

namespace App\Console\Commands;

use App\Models\HumanityLocation;
use App\Models\Store;
use App\Services\Tcp\TcpClientInterface;
use App\Services\Tcp\TcpRateLimiter;
use App\Services\Tcp\TcpWorkSegmentSync;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Pulls worked segments from TCP into actual_shifts — the actual side of
 * planned-vs-actual.
 *
 * Incremental by default via TCP's updatedOn delta filter. `--full` re-reads
 * the whole lookback window and is expensive against a 2500/day quota, so it is
 * for backfills and repairs, not a schedule.
 */
class SyncTcpWorkSegmentsCommand extends Command
{
    protected $signature = 'tcp:sync-worksegments
        {--store= : store_number; omit for every store}
        {--from= : YYYY-MM-DD}
        {--to= : YYYY-MM-DD}
        {--full : Ignore the delta cursor and re-read the window. Expensive.}
        {--dry-run : Count what would be written without touching actual_shifts or the cursor}';

    protected $description = 'Sync worked segments from TCP Manager+ into actual_shifts';

    public function handle(TcpWorkSegmentSync $sync, TcpRateLimiter $limiter, TcpClientInterface $tcp): int
    {
        if (config('tcp.driver') === 'http' && !$tcp->ping()) {
            $this->error('TCP is not reachable — check TCP_CLIENT_ID / TCP_CLIENT_SECRET / TCP_API_KEY.');

            return self::FAILURE;
        }

        $stores = Store::query()
            ->when($this->option('store'), fn ($q, $storeNumber) => $q->where('store_number', $storeNumber))
            ->get();

        if ($stores->isEmpty()) {
            $this->warn('No stores to sync.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'TCP quota: %d used today, %d left for background work.',
            $limiter->usedToday(),
            $limiter->remainingToday()
        ));

        $from = $this->option('from') ? CarbonImmutable::parse($this->option('from'))->startOfDay() : null;
        $to = $this->option('to') ? CarbonImmutable::parse($this->option('to'))->endOfDay() : null;

        $rows = [];
        $exit = self::SUCCESS;

        $guard = app(\App\Services\External\ExternalWriteGuard::class);
        $dryRun = (bool) $this->option('dry-run');

        foreach ($stores as $store) {
            // Rollout allowlist: local mirroring is scoped with everything
            // else while a pilot runs, so an unpiloted store's actual_shifts
            // stay untouched. Dry runs are read-only and stay unrestricted.
            if (!$dryRun && $guard->isRestricted() && !$guard->allows((string) $store->store_number)) {
                $this->line("Skipping {$store->store_number} — not in EXTERNAL_WRITE_ALLOWED_STORES.");

                continue;
            }

            try {
                $stats = $sync->sync($store, $from, $to, (bool) $this->option('full'), $dryRun);

                $rows[] = [
                    $store->store_number,
                    $stats['segments'],
                    $stats['imported'],
                    $stats['updated'],
                    $stats['skipped'],
                    $stats['unlinked'],
                ];
            } catch (\Throwable $e) {
                $this->error("  {$store->store_number}: {$e->getMessage()}");
                $rows[] = [$store->store_number, '—', '—', '—', '—', '—'];
                $exit = self::FAILURE;
            }
        }

        $this->table(
            ['store', 'segments', 'imported', 'updated', 'skipped', 'unlinked'],
            $rows
        );

        // Unlinked segments are worked hours we cannot attribute to anyone —
        // worth surfacing, since it usually means an employee exists in TCP but
        // has no 'TCP ID' external id replicated from hiring.
        $unlinked = array_sum(array_map(fn ($row) => is_int($row[5]) ? $row[5] : 0, $rows));

        if ($unlinked > 0) {
            $this->warn("{$unlinked} segment(s) belong to employees with no TCP link — their hours are not recorded.");
            $this->line('  php artisan tcp:inspect-employees');
        }

        if ($dryRun) {
            $this->comment('Dry run — nothing was written and the delta cursor did not move.');
        }

        return $exit;
    }
}
