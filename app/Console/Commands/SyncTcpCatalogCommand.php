<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Models\TcpJobCode;
use App\Models\TcpLocation;
use App\Services\Tcp\TcpClientInterface;
use Illuminate\Console\Command;

/**
 * Pulls TCP's Locations and job codes into the local catalog tables and binds
 * locations to stores.
 *
 * The binding convention is NAME equality: whoever creates a TCP Location for
 * a store MUST name it with the store_number ("03795-00001"). This command is
 * where that convention is enforced — the binding it writes is what runtime
 * code reads, so a mis-named location surfaces here as an unmatched row
 * instead of as shifts or punches landing in the wrong restaurant.
 *
 * Job codes are attributed to stores via their "Restaurant Id" custom field,
 * not their description — "Crew Member - 3795-01" encodes the store only for
 * humans.
 *
 * Cost: one paged locations read + one paged job-codes read (~6 calls against
 * the 2500/day quota).
 */
class SyncTcpCatalogCommand extends Command
{
    protected $signature = 'tcp:sync-catalog
        {--check : Report what would change without writing anything}';

    protected $description = 'Sync TCP locations + job codes into the mapping tables and verify store bindings';

    public function handle(TcpClientInterface $tcp): int
    {
        if (config('tcp.driver') === 'http' && !$tcp->ping()) {
            $this->error('TCP is not reachable — check TCP_CLIENT_ID / TCP_CLIENT_SECRET / TCP_API_KEY.');

            return self::FAILURE;
        }

        $check = (bool) $this->option('check');

        $exit = $this->syncLocations($tcp, $check);
        $this->syncJobCodes($tcp, $check);

        return $exit;
    }

    private function syncLocations(TcpClientInterface $tcp, bool $check): int
    {
        $locations = collect($tcp->listLocations())
            ->filter(fn ($row) => isset($row['id'], $row['name']));

        $this->info(sprintf('Fetched %d TCP location(s).', $locations->count()));

        $stores = Store::query()->get()->keyBy('store_number');

        $matched = [];
        $renamed = [];
        $unmatchedLocations = [];

        foreach ($locations as $location) {
            $name = trim((string) $location['name']);
            $store = $stores->get($name);

            if ($store === null) {
                $unmatchedLocations[] = "{$location['id']} — {$name}";

                continue;
            }

            // A store already bound to a DIFFERENT TCP location id means either
            // the location was recreated in TCP or the convention broke. Never
            // silently repoint — that changes what every past and future shift
            // and punch for this store means.
            $existing = TcpLocation::query()->where('store_id', $store->id)->first();

            if ($existing !== null && (string) $existing->tcp_location_id !== (string) $location['id']) {
                $this->error(sprintf(
                    '  CONFLICT: store %s is bound to TCP location %s but TCP now serves %s under that name. Resolve by hand.',
                    $store->store_number,
                    $existing->tcp_location_id,
                    $location['id'],
                ));

                continue;
            }

            if ($existing !== null && $existing->name !== $name) {
                $renamed[] = "{$store->store_number}: '{$existing->name}' → '{$name}'";
            }

            $matched[] = $store->store_number;

            if (!$check) {
                TcpLocation::query()->updateOrCreate(
                    ['store_id' => $store->id],
                    [
                        'tcp_location_id' => (string) $location['id'],
                        'name' => $name,
                        'last_synced_at' => now(),
                    ]
                );
            }
        }

        $unmatchedStores = $stores->keys()->diff($matched)->values();

        $this->table(['metric', 'count'], [
            ['locations matched to stores', count($matched)],
            ['stores with NO TCP location', $unmatchedStores->count()],
            ['TCP locations with no store', count($unmatchedLocations)],
        ]);

        foreach ($unmatchedStores as $storeNumber) {
            $this->warn("  Store {$storeNumber} has no TCP location named '{$storeNumber}' — employee pushes and clocking for it will fail.");
        }

        foreach ($unmatchedLocations as $line) {
            $this->line("  TCP location with no store: {$line}");
        }

        foreach ($renamed as $line) {
            $this->warn("  Renamed on the TCP side: {$line}");
        }

        // Exit non-zero when a store is unlinked, so a deploy or a runbook
        // step can gate on this command.
        return $unmatchedStores->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    private function syncJobCodes(TcpClientInterface $tcp, bool $check): void
    {
        $jobCodes = collect($tcp->listJobCodes())
            ->filter(fn ($row) => isset($row['jobCodeId']) || isset($row['id']));

        $this->info(sprintf('Fetched %d TCP job code(s).', $jobCodes->count()));

        $perStore = 0;
        $global = 0;

        foreach ($jobCodes as $row) {
            $storeNumber = $this->restaurantId($row);
            $storeNumber === null ? $global++ : $perStore++;

            if ($check) {
                continue;
            }

            TcpJobCode::query()->updateOrCreate(
                // Punches speak jobCodeId, so that is the identity we keep —
                // TCP's separate record `id` is deliberately not stored.
                ['tcp_job_code_id' => (string) ($row['jobCodeId'] ?? $row['id'])],
                [
                    'description' => (string) ($row['description'] ?? $row['name'] ?? ''),
                    'store_number' => $storeNumber,
                    'clockable' => (bool) ($row['clockable'] ?? false),
                    'is_active' => (bool) ($row['active'] ?? true),
                    'last_synced_at' => now(),
                ]
            );
        }

        $this->table(['metric', 'count'], [
            ['per-store job codes', $perStore],
            ['company-wide job codes', $global],
        ]);

        // A store whose employees cannot clock in is the failure this table
        // exists to prevent, so name the gaps now rather than at punch time.
        foreach (Store::query()->pluck('store_number') as $storeNumber) {
            $count = $check
                ? $jobCodes->filter(fn ($row) => $this->restaurantId($row) === $storeNumber && ($row['clockable'] ?? false))->count()
                : TcpJobCode::query()->where('store_number', $storeNumber)->where('clockable', true)->where('is_active', true)->count();

            if ($count === 0) {
                $this->warn("  Store {$storeNumber} has no clockable TCP job codes — clock-ins for it will fail JOB_CODE_NOT_MAPPED.");
            }
        }

        if ($check) {
            $this->info('Check mode — nothing was written.');
        }
    }

    /** The "Restaurant Id" custom field, TCP's store attribution for a job code. */
    private function restaurantId(array $row): ?string
    {
        foreach ((array) ($row['customFields'] ?? []) as $field) {
            if (is_array($field)
                && strcasecmp(trim((string) ($field['description'] ?? '')), 'Restaurant Id') === 0
            ) {
                $value = trim((string) ($field['value'] ?? ''));

                return $value === '' ? null : $value;
            }
        }

        return null;
    }
}
