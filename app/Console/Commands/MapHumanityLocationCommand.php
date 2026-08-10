<?php

namespace App\Console\Commands;

use App\Models\HumanityLocation;
use App\Models\HumanityPositionMap;
use App\Models\Store;
use App\Services\Humanity\HumanityClientInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Binds a store to a Humanity location.
 *
 * Store numbers ("03759-00001") never resemble Humanity's location names, so
 * this pairing is explicit data rather than anything inferred at runtime — the
 * cost of guessing wrong is writing real shifts into the wrong restaurant.
 *
 * The candidate list is fetched LIVE from Humanity rather than read from a
 * table: `humanity_locations.store_id` is NOT NULL, so that table can only ever
 * hold locations that are already mapped. Fetching also means a mistyped id is
 * rejected here instead of surfacing later as an opaque Humanity error.
 */
class MapHumanityLocationCommand extends Command
{
    protected $signature = 'humanity:map-location
        {--store= : store_number, e.g. 03759-00001}
        {--location= : Humanity location id}
        {--list : Show current mappings and what is still unmapped}
        {--unmap : Remove the mapping for --store}';

    protected $description = 'Map a store to its Humanity location';

    /** @var Collection<int, array>|null */
    private ?Collection $catalogCache = null;

    public function handle(HumanityClientInterface $humanity): int
    {
        if ($this->option('list')) {
            return $this->list($humanity);
        }

        if ($this->option('unmap')) {
            return $this->unmap();
        }

        $store = $this->resolveStore();
        if ($store === null) {
            return self::FAILURE;
        }

        $locationId = $this->resolveLocationId($humanity);
        if ($locationId === null) {
            return self::FAILURE;
        }

        // One location per store, and one store per location: a shared mapping
        // would silently merge two restaurants' schedules.
        $clash = HumanityLocation::query()
            ->where('humanity_location_id', $locationId)
            ->where('store_id', '!=', $store->id)
            ->first();

        if ($clash !== null) {
            $clashStore = Store::query()->find($clash->store_id);
            $this->error("Humanity location {$locationId} is already mapped to store {$clashStore?->store_number}.");
            $this->line('Unmap that store first if you really mean to move it.');

            return self::FAILURE;
        }

        $existing = HumanityLocation::query()->where('store_id', $store->id)->first();

        if ($existing !== null && $existing->humanity_location_id !== $locationId) {
            $this->warn("Store {$store->store_number} is currently mapped to {$existing->humanity_location_id}.");

            if (!$this->confirmRemap()) {
                return self::FAILURE;
            }
        }

        $entry = $this->catalog($humanity)->firstWhere('id', $locationId);

        HumanityLocation::query()->updateOrCreate(
            ['store_id' => $store->id],
            [
                'humanity_location_id' => $locationId,
                'name' => $entry['name'] ?? null,
                'timezone' => $entry['timezone'] ?? null,
                'last_synced_at' => now(),
            ]
        );

        $this->info("Mapped store {$store->store_number} → Humanity location {$locationId}"
            . (!empty($entry['name']) ? " ({$entry['name']})" : ''));

        $this->reportTimezone($store, $entry['timezone'] ?? null);
        $this->warnIfNoDefaultPosition($store);

        return self::SUCCESS;
    }

    private function list(HumanityClientInterface $humanity): int
    {
        $mapped = HumanityLocation::query()->get();
        $storesById = Store::query()->pluck('store_number', 'id');

        $this->line('<info>Mapped</info>');

        if ($mapped->isEmpty()) {
            $this->line('  (none)');
        } else {
            $this->table(
                ['store_number', 'humanity_location_id', 'location name', 'timezone'],
                $mapped->map(fn (HumanityLocation $row) => [
                    $storesById[$row->store_id] ?? "store #{$row->store_id}",
                    $row->humanity_location_id,
                    $row->name ?? '—',
                    $row->timezone ?? '—',
                ])->all()
            );
        }

        $unmappedStores = Store::query()
            ->whereNotIn('id', HumanityLocation::query()->select('store_id'))
            ->pluck('store_number');

        if ($unmappedStores->isNotEmpty()) {
            $this->newLine();
            $this->warn('Stores with no Humanity location — shift writes 422 STORE_NOT_MAPPED:');
            foreach ($unmappedStores as $storeNumber) {
                $this->line("  {$storeNumber}");
            }
        }

        $claimed = HumanityLocation::query()->pluck('humanity_location_id')->all();
        $free = $this->catalog($humanity)->reject(fn (array $row) => in_array($row['id'], $claimed, true));

        $this->newLine();
        $this->line('<info>Humanity locations not yet claimed by a store</info>');

        if ($free->isEmpty()) {
            $this->line('  (none)');
        } else {
            foreach ($free as $row) {
                $this->line("  {$row['id']}  {$row['name']}");
            }
        }

        return self::SUCCESS;
    }

    private function unmap(): int
    {
        $store = $this->resolveStore();
        if ($store === null) {
            return self::FAILURE;
        }

        $deleted = HumanityLocation::query()->where('store_id', $store->id)->delete();

        if ($deleted === 0) {
            $this->warn("Store {$store->store_number} had no mapping.");

            return self::SUCCESS;
        }

        $this->info("Unmapped store {$store->store_number}. Shift writes for it will fail until re-mapped.");

        return self::SUCCESS;
    }

    private function resolveStore(): ?Store
    {
        $storeNumber = $this->option('store');

        if ($storeNumber === null) {
            $choices = Store::query()->orderBy('store_number')->pluck('store_number')->all();

            if ($choices === []) {
                $this->error('No stores exist yet. They replicate from pizzasys via auth.v1.store.*.');

                return null;
            }

            $storeNumber = $this->choice('Which store?', $choices);
        }

        $store = Store::query()->where('store_number', $storeNumber)->first();

        if ($store === null) {
            $this->error("Store {$storeNumber} not found. Stores replicate from pizzasys — check nats:consume is running.");

            return null;
        }

        return $store;
    }

    private function resolveLocationId(HumanityClientInterface $humanity): ?string
    {
        $catalog = $this->catalog($humanity);

        if ($catalog->isEmpty()) {
            $this->error('Humanity returned no locations.');
            $this->line('Check HUMANITY_DRIVER=http and the credentials, then retry.');

            return null;
        }

        $locationId = $this->option('location');

        if ($locationId === null) {
            $labels = $catalog->map(fn (array $row) => "{$row['id']}  {$row['name']}")->all();
            $locationId = strtok($this->choice('Which Humanity location?', $labels), ' ');
        }

        if (!$catalog->contains(fn (array $row) => $row['id'] === $locationId)) {
            $this->error("Humanity location {$locationId} does not exist.");
            $this->line('Run with --list to see the real ids.');

            return null;
        }

        return $locationId;
    }

    private function confirmRemap(): bool
    {
        if (!$this->input->isInteractive()) {
            $this->error('Refusing to silently re-map a store non-interactively. Use --unmap first.');

            return false;
        }

        // Existing shifts keep their old humanity_location_id, so a re-map
        // splits the store's history across two locations.
        return $this->confirm('Re-map it? Shifts already written keep the old location.', false);
    }

    /** @return Collection<int, array{id:string,name:string,timezone:?string}> */
    private function catalog(HumanityClientInterface $humanity): Collection
    {
        // NOTE: listLocations() passes filter[include_primary]=1 — without it
        // Humanity silently omits the primary location and one store could
        // never be mapped at all.
        return $this->catalogCache ??= collect($humanity->listLocations());
    }

    /**
     * This mapping decides the store's scheduling timezone: Humanity has no
     * per-request timezone and interprets every date and "HH:MM" we send in its
     * location's local time, so getting it wrong shifts every hour silently.
     */
    private function reportTimezone(Store $store, ?string $remoteTimezone): void
    {
        $effective = app(\App\Services\Scheduling\StoreTimezoneResolver::class)->for($store->fresh());

        $this->newLine();

        if ($remoteTimezone === null) {
            $this->warn('Humanity reported no timezone for this location.');
            $this->warn("Shifts will be written in the configured default: {$effective}");
            $this->line('Set the timezone on the location in Humanity if that is wrong.');

            return;
        }

        $this->line("Shift times for this store will be interpreted as <info>{$effective}</info>.");
    }

    private function warnIfNoDefaultPosition(Store $store): void
    {
        $hasDefault = HumanityPositionMap::query()
            ->where('store_id', $store->id)
            ->whereNull('position_id')
            ->exists();

        if (!$hasDefault) {
            $this->newLine();
            $this->warn('This store has no default Humanity position, so shift creation will still');
            $this->warn('fail with POSITION_NOT_MAPPED. Fix it with:');
            $this->line("  php artisan humanity:map-position --store={$store->store_number} --default");
        }
    }
}
