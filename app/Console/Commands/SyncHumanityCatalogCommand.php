<?php

namespace App\Console\Commands;

use App\Models\HumanityLocation;
use App\Models\HumanityPosition;
use App\Models\HumanityPositionMap;
use App\Models\Store;
use App\Services\Humanity\HumanityClientInterface;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Pulls Humanity's locations and positions into our mapping tables.
 *
 * Locations and positions are the ONLY Humanity resources with a real delta
 * filter (?updated_at=&include_deleted=1), so this is cheap to run often.
 * Everything else has to be polled wholesale.
 */
class SyncHumanityCatalogCommand extends Command
{
    protected $signature = 'humanity:sync-catalog
        {--full : Ignore the delta cursor and re-read everything}
        {--auto-map : Match store_number to a location name and write the mapping}';

    protected $description = 'Sync Humanity locations and positions into the mapping tables';

    public function handle(HumanityClientInterface $humanity): int
    {
        $since = $this->option('full') ? null : $this->cursor();

        $locations = $humanity->listLocations($since);
        $this->info(sprintf('Fetched %d location(s).', count($locations)));

        foreach ($locations as $location) {
            $this->upsertLocation($location);
        }

        $positions = $humanity->listPositions($since);
        $this->info(sprintf('Fetched %d position(s).', count($positions)));

        foreach ($positions as $position) {
            HumanityPosition::query()->updateOrCreate(
                ['humanity_position_id' => $position['id']],
                [
                    'humanity_location_id' => $position['location_id'] ?? null,
                    'name' => $position['name'],
                    'color' => $position['color'] ?? null,
                    'is_active' => (bool) ($position['is_active'] ?? true),
                    'remote_updated_at' => $position['updated_at'] ?? null,
                    'deleted_remotely_at' => ($position['is_active'] ?? true) ? null : now(),
                    'last_synced_at' => now(),
                ]
            );
        }

        $this->reportUnmapped();

        return self::SUCCESS;
    }

    private function upsertLocation(array $location): void
    {
        $existing = HumanityLocation::query()
            ->where('humanity_location_id', $location['id'])
            ->first();

        if ($existing !== null) {
            $existing->update([
                'name' => $location['name'],
                'timezone' => $location['timezone'] ?? $existing->timezone,
                'last_synced_at' => now(),
            ]);

            return;
        }

        if (!$this->option('auto-map')) {
            $this->line("  Unmapped Humanity location: {$location['id']} — {$location['name']}");

            return;
        }

        // Matching by name is a convenience, never a guess we act on silently:
        // the wrong store↔location pairing writes real shifts into the wrong
        // restaurant, so an ambiguous match is reported and skipped.
        $store = Store::query()
            ->where('store_number', $location['name'])
            ->orWhere('name', $location['name'])
            ->get();

        if ($store->count() !== 1) {
            $this->warn("  Cannot auto-map location '{$location['name']}': matched {$store->count()} stores.");

            return;
        }

        HumanityLocation::query()->create([
            'store_id' => $store->first()->id,
            'humanity_location_id' => $location['id'],
            'name' => $location['name'],
            'timezone' => $location['timezone'] ?? null,
            'last_synced_at' => now(),
        ]);

        $this->info("  Mapped store {$store->first()->store_number} → Humanity location {$location['id']}.");
    }

    /**
     * Stores with no location, and stores with no default position — both make
     * every shift write fail with a 422, so surface them loudly.
     */
    private function reportUnmapped(): void
    {
        $unmappedStores = Store::query()
            ->whereNotIn('id', HumanityLocation::query()->select('store_id'))
            ->pluck('store_number');

        if ($unmappedStores->isNotEmpty()) {
            $this->warn('Stores with no Humanity location: ' . $unmappedStores->implode(', '));
        }

        $withoutDefault = Store::query()
            ->whereIn('id', HumanityLocation::query()->select('store_id'))
            ->whereNotIn('id', HumanityPositionMap::query()->whereNull('position_id')->select('store_id'))
            ->pluck('store_number');

        if ($withoutDefault->isNotEmpty()) {
            $this->warn('Stores with no default Humanity position: ' . $withoutDefault->implode(', '));
            $this->line('  Shift creation will fail with POSITION_NOT_MAPPED until one is set.');
        }
    }

    private function cursor(): ?CarbonImmutable
    {
        $latest = HumanityPosition::query()->max('last_synced_at');

        return $latest === null ? null : CarbonImmutable::parse($latest)->subHour();
    }
}
