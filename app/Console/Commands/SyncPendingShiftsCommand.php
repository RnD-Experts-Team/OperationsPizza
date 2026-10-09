<?php

namespace App\Console\Commands;

use App\Models\Shift;
use App\Services\Humanity\PendingShiftSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Push shifts that a Humanity throttle left owed.
 *
 * Ordered by shift DATE across every store, which is the point: when 38 stores
 * publish the same week and the account throttles, this drains Monday for all
 * of them before any store's Tuesday. A store missing its last day is a
 * nuisance; a store missing its first day while another has a full week is the
 * failure this ordering exists to prevent.
 */
class SyncPendingShiftsCommand extends Command
{
    protected $signature = 'humanity:sync-pending-shifts
                            {--limit=200 : Stop after this many shifts}
                            {--dry-run : List what would be pushed, call nothing}';

    protected $description = 'Push shifts to Humanity that a throttle left owed.';

    public function handle(PendingShiftSyncService $sync): int
    {
        // The account already told us to stop. Trying now would spend calls to
        // be refused, and would burn a retry attempt on a shift whose only
        // problem is timing.
        if (!$this->option('dry-run') && $sync->inCooldown()) {
            $this->info('Humanity is in a throttle cooldown; skipping this pass.');

            return self::SUCCESS;
        }

        $due = Shift::withTrashed()
            ->where('sync_status', Shift::SYNC_PENDING)
            ->where(function ($query) {
                $query->whereNull('sync_next_attempt_at')
                    ->orWhere('sync_next_attempt_at', '<=', CarbonImmutable::now());
            })
            ->orderBy('shift_date')
            ->orderBy('store_id')
            ->orderBy('start_time')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($due->isEmpty()) {
            $this->info('Nothing owed to Humanity.');

            return self::SUCCESS;
        }

        $this->line("{$due->count()} shift(s) owed to Humanity.");

        if ($this->option('dry-run')) {
            foreach ($due as $shift) {
                $this->line(sprintf(
                    '  store %s  %s  shift %d  attempts %d  %s',
                    $shift->store_id,
                    $shift->shift_date?->toDateString() ?? '—',
                    $shift->id,
                    $shift->sync_attempts,
                    $shift->humanity_shift_id === null ? 'create' : ($shift->trashed() ? 'delete' : 'update'),
                ));
            }

            return self::SUCCESS;
        }

        $synced = 0;

        foreach ($due as $shift) {
            if (!$sync->syncOne($shift)) {
                // Throttled again. Stop the whole pass rather than march the
                // rest of the backlog into the same wall — each attempt costs
                // one of only four before the shift is parked for a human.
                $this->warn('Humanity throttled us again; stopping this pass.');

                break;
            }

            $synced++;
        }

        $this->info("Synced {$synced} shift(s).");

        $parked = Shift::withTrashed()->where('sync_status', Shift::SYNC_PARKED)->count();

        if ($parked > 0) {
            $this->warn("{$parked} shift(s) are PARKED and need a human — they exist locally but not in Humanity.");
        }

        return self::SUCCESS;
    }
}
