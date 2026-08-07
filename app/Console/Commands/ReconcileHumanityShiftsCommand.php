<?php

namespace App\Console\Commands;

use App\Models\HumanityLocation;
use App\Models\Store;
use App\Services\Reconciliation\HumanityShiftReconciler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ReconcileHumanityShiftsCommand extends Command
{
    protected $signature = 'humanity:reconcile
        {--store= : store_number; omit for every mapped store}
        {--from= : YYYY-MM-DD, defaults to today minus the configured window}
        {--to= : YYYY-MM-DD, defaults to today plus the configured window}
        {--dry-run : Report what would change without touching anything}';

    protected $description = 'Reconcile our shift mirror against Humanity (the source of truth)';

    public function handle(HumanityShiftReconciler $reconciler): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Humanity production is live. The first pass against it IMPORTS the
        // existing schedule and can soft-delete local rows, so it must be a
        // deliberate act, never a surprise cron.
        if (!$dryRun && config('humanity.environment') === 'production' && !$this->confirmProduction()) {
            return self::FAILURE;
        }

        $from = CarbonImmutable::parse(
            $this->option('from') ?? now()->subDays((int) config('humanity.reconcile.days_back', 7))->toDateString()
        );

        $to = CarbonImmutable::parse(
            $this->option('to') ?? now()->addDays((int) config('humanity.reconcile.days_forward', 21))->toDateString()
        );

        $stores = $this->stores();

        if ($stores->isEmpty()) {
            $this->warn('No mapped stores to reconcile. Run humanity:sync-catalog first.');

            return self::SUCCESS;
        }

        $exitCode = self::SUCCESS;

        foreach ($stores as $store) {
            $this->line("Reconciling {$store->store_number} ({$from->toDateString()} → {$to->toDateString()})...");

            $report = $reconciler->reconcile($store, $from, $to, $dryRun);

            $this->table(
                ['remote', 'unchanged', 'imported', 'updated', 'deleted', 'skipped'],
                [[
                    $report->remoteSeen,
                    $report->unchanged,
                    $report->imported,
                    $report->updated,
                    $report->deleted,
                    $report->skipped,
                ]]
            );

            foreach ($report->errors as $error) {
                $this->error("  {$error}");
                $exitCode = self::FAILURE;
            }
        }

        if ($dryRun) {
            $this->comment('Dry run — nothing was written.');
        }

        return $exitCode;
    }

    private function stores()
    {
        $query = Store::query()->whereIn('id', HumanityLocation::query()->select('store_id'));

        if ($storeNumber = $this->option('store')) {
            $query->where('store_number', $storeNumber);
        }

        return $query->get();
    }

    private function confirmProduction(): bool
    {
        if (!$this->input->isInteractive()) {
            $this->error('Refusing to reconcile against Humanity production non-interactively. Use --dry-run first.');

            return false;
        }

        return $this->confirm(
            'HUMANITY_ENV is production. This can import live shifts and soft-delete local rows. Continue?',
            false
        );
    }
}
