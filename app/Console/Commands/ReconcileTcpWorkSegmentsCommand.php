<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Store;
use App\Models\TcpLocation;
use App\Models\TcpWorkSegment as WorkSegmentRow;
use App\Services\Scheduling\ActualShiftRollup;
use App\Services\Scheduling\StoreTimezoneResolver;
use App\Services\Tcp\Exceptions\TcpAuthException;
use App\Services\Tcp\Exceptions\TcpRateLimitException;
use App\Services\Tcp\TcpClientInterface;
use App\Services\Tcp\TcpRateLimiter;
use App\Services\Tcp\TcpWorkSegmentSync;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The nightly truth pass over worked time.
 *
 * `tcp:sync-worksegments` is a DELTA, and a delta has two blind spots that no
 * amount of tuning fixes:
 *
 *   DELETIONS       a segment voided in TCP simply stops being returned, which
 *                   is indistinguishable from "unchanged". Nothing in a delta
 *                   can ever notice it, so an orphan row would be paid out
 *                   forever.
 *
 *   THE CHANGE GATE the delta skips a store outright when /calculationchanges
 *                   does not name any of its people — and TCP does not document
 *                   whether that feed reflects raw punch inserts or only its own
 *                   recalculations. Re-reading the window unconditionally bounds
 *                   that risk to a day instead of leaving it open forever.
 *
 * It also answers the question the delta structurally cannot: WHO IS MISSING.
 * The delta filters on employeeIds, so TCP only ever returns people we already
 * knew to ask about; somebody working at a store with no local link is invisible
 * by construction. Asking TCP for the store's own roster is the only way to see
 * them.
 *
 * Mark and sweep: the sync stamps `last_seen_at` on every segment TCP hands
 * back, so anything in the window still carrying an older stamp is something TCP
 * no longer has.
 */
class ReconcileTcpWorkSegmentsCommand extends Command
{
    protected $signature = 'tcp:reconcile-worksegments
        {--store= : store_number; omit for every store}
        {--days= : How far back to sweep. Defaults to tcp.timeclock.lookback_days.}
        {--dry-run : Report what would change without touching anything}';

    protected $description = 'Re-read the payroll window from TCP, retire segments TCP no longer has, and report unmapped people';

    public function handle(
        TcpWorkSegmentSync $sync,
        ActualShiftRollup $rollup,
        TcpClientInterface $tcp,
        TcpRateLimiter $limiter,
        StoreTimezoneResolver $timezones,
    ): int {
        $stores = Store::query()
            ->when($this->option('store'), fn ($q, $storeNumber) => $q->where('store_number', $storeNumber))
            ->get();

        if ($stores->isEmpty()) {
            $this->warn('No stores to reconcile.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $days = (int) ($this->option('days') ?: config('tcp.timeclock.lookback_days', 14));

        $this->line(sprintf(
            'TCP quota: %d used today, %d left for background work.',
            $limiter->usedToday(),
            $limiter->remainingToday()
        ));

        $rows = [];
        $exit = self::SUCCESS;

        foreach ($stores as $store) {
            $timezone = $timezones->for($store);
            $from = CarbonImmutable::now($timezone)->subDays($days)->startOfDay();
            $to = CarbonImmutable::now($timezone)->addDay()->endOfDay();

            try {
                // full: true — ignore the cursor AND the changes gate. That is
                // the whole point of this pass.
                $stats = $sync->sync($store, $from, $to, true, $dryRun);

                $retired = $dryRun
                    ? $this->staleQuery($store, $from, $to, $stats)->count()
                    : $this->retire($store, $from, $to, $stats, $rollup);

                $unconfirmed = $this->unconfirmed($store, $from, $to);
                $unmapped = $this->unmappedEmployees($store, $tcp);

                $rows[] = [
                    $store->store_number,
                    $stats['segments'],
                    $stats['imported'],
                    $stats['updated'],
                    $retired,
                    $unconfirmed,
                    $unmapped === null ? '?' : count($unmapped),
                ];

                if (!empty($unmapped)) {
                    $this->warn("  {$store->store_number}: TCP has " . count($unmapped) . ' employee(s) here with no local link:');
                    $this->line('    ' . implode(', ', array_slice($unmapped, 0, 20)));
                }
            } catch (TcpAuthException $e) {
                // Credentials, not this store. Every remaining store would fail
                // the same way, so stop rather than spend the rest of the budget
                // proving it.
                $this->error("  {$store->store_number}: {$e->getMessage()}");
                $this->error('Aborting: TCP rejected our credentials.');

                return self::FAILURE;
            } catch (TcpRateLimitException $e) {
                // The budget is account-wide, so it is spent for every store.
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
            ['store', 'segments', 'imported', 'updated', 'retired', 'unconfirmed', 'unmapped'],
            $rows
        );

        if ($dryRun) {
            $this->comment('Dry run — nothing was written and no segment was retired.');
        }

        return $exit;
    }

    /**
     * Retire segments TCP no longer has, and rebuild what they belonged to.
     *
     * Soft, never hard: a segment that vanishes from TCP is a payroll event
     * somebody may need to explain later, and hard-deleting the evidence makes
     * that impossible.
     *
     * The roll-up behind it is recomputed, never silently discarded — if a
     * manager had already signed those hours off, their verdict is preserved and
     * the row resurfaces with needs_attention instead. ActualShiftRollup owns
     * that rule; this only has to trigger it.
     */
    private function retire(
        Store $store,
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $stats,
        ActualShiftRollup $rollup,
    ): int {
        $stale = $this->staleQuery($store, $from, $to, $stats)->get();

        if ($stale->isEmpty()) {
            return 0;
        }

        // Captured before deleting: afterwards the segments carry no grouping to
        // rebuild from.
        $affected = $stale
            ->map(fn (WorkSegmentRow $s) => [
                'employee_id' => $s->employee_id,
                'anchor' => CarbonImmutable::parse($s->starts_at_utc),
            ])
            ->unique(fn (array $a) => $a['employee_id'] . '|' . $a['anchor']->toDateString())
            ->values();

        foreach ($stale as $segment) {
            $this->line(sprintf(
                '  %s: retiring segment %s (%s) — TCP no longer has it.',
                $store->store_number,
                $segment->tcp_work_segment_id,
                $segment->starts_at_utc?->toDateTimeString() ?? '?'
            ));

            $segment->delete();
        }

        foreach ($affected as $entry) {
            $employee = Employee::find($entry['employee_id']);

            if ($employee !== null) {
                $rollup->rebuild($store, $employee, $entry['anchor']);
            }
        }

        return $stale->count();
    }

    /**
     * Segments in the window that this pass did NOT get back from TCP.
     *
     * Diffed on identity rather than on a `last_seen_at` timestamp. A timestamp
     * cannot tell "re-seen just now" from "last seen a moment ago" when both
     * land in the same second, and being wrong in that direction deletes real
     * hours.
     *
     * Scoped to the people this pass actually asked about, which is the
     * important guard: an employee skipped for quota, or with no TCP link, was
     * never looked up — and "we did not ask" must never be read as "TCP does
     * not have it".
     */
    private function staleQuery(Store $store, CarbonImmutable $from, CarbonImmutable $to, array $stats)
    {
        return WorkSegmentRow::query()
            ->where('store_id', $store->id)
            ->whereBetween('starts_at_utc', [$from->utc(), $to->utc()])
            ->whereIn('employee_id', $stats['queried_employee_ids'] ?? [])
            ->whereNotIn('tcp_work_segment_id', $stats['seen_segment_ids'] ?? []);
    }

    /** Rows never once confirmed against TCP — reported, never retired. */
    private function unconfirmed(Store $store, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return WorkSegmentRow::query()
            ->where('store_id', $store->id)
            ->whereBetween('starts_at_utc', [$from->utc(), $to->utc()])
            ->whereNull('last_seen_at')
            ->count();
    }

    /**
     * People TCP says work at this store that we have no link for.
     *
     * The only way to see them. `GET /worksegments` has no location filter —
     * confirmed against TCP's OpenAPI spec, which offers fifteen query
     * parameters and not one of them narrows by location — so the roster has to
     * come from `GET /employees?locations=`, which does.
     *
     * One extra call per store. Affordable nightly; it is emphatically not
     * affordable on the ten-minute sync, which is why that one still works from
     * our own roster and relies on this pass to tell it what it is missing.
     *
     * Returns null when the store has no TCP location mapped, which is a
     * different thing from "nobody is missing".
     *
     * @return array<int, string>|null
     */
    private function unmappedEmployees(Store $store, TcpClientInterface $tcp): ?array
    {
        $location = TcpLocation::query()->where('store_id', $store->id)->first();

        if ($location === null || blank($location->name)) {
            return null;
        }

        try {
            $remote = $tcp->listEmployees(['locations' => $location->name]);
        } catch (\Throwable $e) {
            $this->warn("  {$store->store_number}: could not read TCP's roster — {$e->getMessage()}");

            return null;
        }

        $remoteIds = array_values(array_filter(array_map(
            fn ($row) => isset($row['employeeId']) ? (string) $row['employeeId'] : null,
            $remote
        )));

        if ($remoteIds === []) {
            return [];
        }

        $known = Employee::query()
            ->whereIn('tcp_employee_id', $remoteIds)
            ->pluck('tcp_employee_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        return array_values(array_diff($remoteIds, $known));
    }
}
