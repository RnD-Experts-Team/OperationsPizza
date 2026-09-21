<?php

namespace App\Console\Commands;

use App\Models\HumanityLocation;
use App\Models\Store;
use App\Services\Tcp\Exceptions\TcpAuthException;
use App\Services\Tcp\Exceptions\TcpRateLimitException;
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

    public function handle(TcpWorkSegmentSync $sync, TcpRateLimiter $limiter): int
    {
        // No ping. It cost one call on EVERY run to answer a question the next
        // call answers for free, which at a ten-minute schedule is ~144/day out
        // of 2500 spent on nothing. Bad credentials are caught by the abort
        // below instead — which is also strictly cheaper than the ping was in
        // the failure case, where the old code still let all 38 stores try.
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

        $dryRun = (bool) $this->option('dry-run');

        /*
         | No allowlist check here any more.
         |
         | EXTERNAL_WRITE_ALLOWED_STORES is a WRITE guard, and this is a read:
         | it pulls TCP's own record into local tables and sends TCP nothing.
         | Gating it meant an unpiloted store had no clocking data at all — no
         | worked hours, and nobody visible on the clock — which is the opposite
         | of what a rollout guard is for.
         |
         | Punches and segment edits are still fully gated, in TcpClockService
         | and ActualShiftService.
         */
        foreach ($stores as $store) {
            try {
                $stats = $sync->sync($store, $from, $to, (bool) $this->option('full'), $dryRun);

                $rows[] = [
                    $store->store_number,
                    $stats['segments'],
                    $stats['imported'],
                    $stats['updated'],
                    $stats['rollups'],
                    $stats['skipped'],
                    $stats['unlinked'],
                ];
            } catch (TcpAuthException $e) {
                // Credentials, not this store. Every remaining store would fail
                // the same way, so stop rather than spend 37 more calls proving
                // it — the quota is small enough that a bad deploy could burn a
                // meaningful part of the day's budget on identical failures.
                $this->error("  {$store->store_number}: {$e->getMessage()}");
                $this->error('Aborting: TCP rejected our credentials — check TCP_CLIENT_ID / TCP_CLIENT_SECRET / TCP_API_KEY.');

                return self::FAILURE;
            } catch (TcpRateLimitException $e) {
                // Same reasoning: the budget is account-wide, so it is spent
                // for every store, not just this one.
                $this->error("  {$store->store_number}: {$e->getMessage()}");
                $this->warn('Aborting: the remaining stores would only collect the same refusal.');

                return self::FAILURE;
            } catch (\Throwable $e) {
                $this->error("  {$store->store_number}: {$e->getMessage()}");
                $rows[] = [$store->store_number, '—', '—', '—', '—', '—', '—'];
                $exit = self::FAILURE;
            }
        }

        $this->table(
            ['store', 'segments', 'imported', 'updated', 'rollups', 'skipped', 'unlinked'],
            $rows
        );

        // Unlinked segments are worked hours we cannot attribute to anyone —
        // worth surfacing, since it usually means an employee exists in TCP but
        // has no 'TCP ID' external id replicated from hiring.
        $unlinked = array_sum(array_map(fn ($row) => is_int($row[6]) ? $row[6] : 0, $rows));

        if ($unlinked > 0) {
            $this->warn("{$unlinked} segment(s) belong to employees with no TCP link — their hours are not recorded.");
            $this->line('  php artisan tcp:inspect-employees');
        }

        // Worth being honest about what this number can and cannot see: the
        // query filters on employeeIds, so TCP only returns people we already
        // asked about. Anyone missing from our roster entirely is invisible
        // here by construction — tcp:reconcile-worksegments is what finds them.


        if ($dryRun) {
            $this->comment('Dry run — nothing was written and the delta cursor did not move.');
        }

        return $exit;
    }
}
