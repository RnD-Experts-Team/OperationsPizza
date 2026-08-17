<?php

namespace App\Console\Commands;

use App\Models\HumanityLocation;
use App\Models\Store;
use App\Services\External\ExternalWriteGuard;
use App\Services\Reconciliation\HumanityShiftReconciler;
use App\Services\Reconciliation\ReconciliationReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ReconcileHumanityShiftsCommand extends Command
{
    protected $signature = 'humanity:reconcile
        {--store= : store_number; omit for every mapped store}
        {--from= : YYYY-MM-DD, defaults to today minus the configured window}
        {--to= : YYYY-MM-DD, defaults to today plus the configured window}
        {--dry-run : Report what would change without touching anything}
        {--force : Skip the confirmation prompt (for cron)}';

    protected $description = 'Reconcile our shift mirror against Humanity (the source of truth)';

    public function handle(HumanityShiftReconciler $reconciler, ExternalWriteGuard $guard): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Humanity is production — there is no sandbox. The first pass against
        // a store IMPORTS its live schedule and can soft-delete local rows, so
        // a non-dry-run is always a deliberate act: confirmed interactively,
        // or explicitly --force'd by the schedule once it has been earned.
        if (!$dryRun && !$this->option('force') && !$this->confirmDestructive()) {
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
            // The rollout allowlist scopes the DESTRUCTIVE pass. Dry runs stay
            // unrestricted — reading what would change is exactly what you
            // want for a store you have not enabled yet.
            if (!$dryRun && !$guard->allows((string) $store->store_number)) {
                $this->line("Skipping {$store->store_number} — not in EXTERNAL_WRITE_ALLOWED_STORES.");

                continue;
            }

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

            $this->printChanges($report);

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

    /**
     * The diff IS the dry run's product: "3 would be deleted" is not enough
     * information to approve a destructive pass with.
     */
    private function printChanges(ReconciliationReport $report): void
    {
        foreach ($report->changes as $change) {
            $line = match ($change['action']) {
                'imported' => sprintf(
                    '  + import %s %s %s–%s (%d assigned)',
                    $change['humanity_shift_id'],
                    $change['date'] ?? '?',
                    $change['start'] ?? '?',
                    $change['end'] ?? '?',
                    count($change['employees'] ?? []),
                ),
                'updated' => sprintf(
                    '  ~ update shift %d (%s): %s',
                    $change['shift_id'],
                    $change['date'] ?? '?',
                    $this->summariseDiff($change['diff'] ?? []),
                ),
                'deleted' => sprintf(
                    '  - delete shift %d (%s) — missing upstream (Humanity %s)',
                    $change['shift_id'],
                    $change['date'] ?? '?',
                    $change['humanity_shift_id'] ?? '?',
                ),
                default => null,
            };

            if ($line !== null) {
                $this->line($line);
            }
        }
    }

    private function summariseDiff(array $diff): string
    {
        if ($diff === []) {
            return '(no field detail)';
        }

        $parts = [];

        foreach ($diff as $field => $change) {
            $from = is_scalar($change['from'] ?? null) ? (string) $change['from'] : json_encode($change['from'] ?? null);
            $to = is_scalar($change['to'] ?? null) ? (string) $change['to'] : json_encode($change['to'] ?? null);
            $parts[] = "{$field}: {$from} → {$to}";
        }

        return implode(', ', $parts);
    }

    private function stores()
    {
        $query = Store::query()->whereIn('id', HumanityLocation::query()->select('store_id'));

        if ($storeNumber = $this->option('store')) {
            $query->where('store_number', $storeNumber);
        }

        return $query->get();
    }

    private function confirmDestructive(): bool
    {
        if (!$this->input->isInteractive()) {
            $this->error('Refusing to reconcile non-interactively without --force. Use --dry-run first and read the diff.');

            return false;
        }

        return $this->confirm(
            'This can import live Humanity shifts and soft-delete local rows. Have you read a --dry-run diff for this window?',
            false
        );
    }
}
