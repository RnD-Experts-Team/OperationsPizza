<?php

namespace App\Console\Commands;

use App\Models\HumanityLocation;
use App\Models\HumanityPosition;
use App\Models\HumanityPositionMap;
use App\Models\Position;
use App\Models\Store;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Maps one of our positions to a Humanity position, per store.
 *
 * Humanity's object model is Location -> Position -> Shift, and its shift API
 * spells Position as the `schedule` parameter. Every shift must belong to one,
 * so a store needs AT MINIMUM a default row (position_id NULL) before any shift
 * can be created — that is the fallback HumanityPositionResolver lands on when
 * an employee has no mapped position of their own.
 *
 * Our positions replicate from HiringPizza inside employee snapshots, so this
 * only offers positions that have actually arrived.
 */
class MapHumanityPositionCommand extends Command
{
    protected $signature = 'humanity:map-position
        {--store= : store_number, e.g. 03759-00001; omit with --auto-map to cover every store}
        {--humanity-position= : Humanity position id}
        {--position= : our positions.id; omit together with --default}
        {--default : Map the store default (position_id NULL) used when an employee has no mapped position}
        {--list : Show this store\'s position mappings}
        {--unmap : Remove the mapping identified by --position/--default}
        {--auto-map : Bulk-map every local position to a Humanity position per store, by store-suffixed label prefix}
        {--force : With --auto-map, overwrite an existing mapping that disagrees with the auto-computed match}';

    protected $description = 'Map a position to its Humanity position for a store';

    public function handle(): int
    {
        if ($this->option('auto-map')) {
            return $this->autoMap();
        }

        $store = $this->resolveStore();
        if ($store === null) {
            return self::FAILURE;
        }

        if ($this->option('list')) {
            return $this->list($store);
        }

        if ($this->option('unmap')) {
            return $this->unmap($store);
        }

        $positionId = $this->resolvePositionId();
        if ($positionId === false) {
            return self::FAILURE;
        }

        $humanityPositionId = $this->resolveHumanityPositionId($store);
        if ($humanityPositionId === null) {
            return self::FAILURE;
        }

        $isDefault = $positionId === null;

        HumanityPositionMap::query()->updateOrCreate(
            ['store_id' => $store->id, 'position_id' => $positionId],
            ['humanity_position_id' => $humanityPositionId, 'is_default' => $isDefault]
        );

        $label = $isDefault
            ? 'default'
            : (Position::query()->find($positionId)?->label ?? "position #{$positionId}");

        $humanityName = HumanityPosition::query()
            ->where('humanity_position_id', $humanityPositionId)
            ->value('name');

        $this->info("Mapped {$store->store_number} / {$label} → Humanity position {$humanityPositionId}"
            . ($humanityName ? " ({$humanityName})" : ''));

        return self::SUCCESS;
    }

    /**
     * Bulk-maps every local position to a Humanity position, per store, by the
     * same store-suffixed label-prefix convention TcpJobCodeResolver uses for
     * TCP job codes ("Crew Member" -> "Crew Member - 3795-01").
     *
     * The real catalog carries BOTH a bare label ("Crew Member") and per-store
     * suffixed ones for the same role, so matching against the full candidate
     * set (store-scoped + global, which activeHumanityPositions() deliberately
     * unions) would make every match ambiguous. The store-scoped tier is tried
     * first; the global tier is only a fallback when nothing store-scoped
     * matches.
     *
     * Never touches the position_id=NULL default row (that stays a deliberate,
     * manual --default action), and never overwrites a mapping that already
     * disagrees with the computed match unless --force is passed — this must
     * be safe to re-run without undoing hand-made corrections.
     */
    private function autoMap(): int
    {
        if ($this->option('position') !== null
            || $this->option('humanity-position') !== null
            || $this->option('default')
            || $this->option('list')
            || $this->option('unmap')
        ) {
            $this->error('--auto-map cannot be combined with --position, --humanity-position, --default, --list, or --unmap.');

            return self::FAILURE;
        }

        $storeNumber = $this->option('store');

        $stores = $storeNumber !== null
            ? Store::query()->where('store_number', $storeNumber)->get()
            : Store::query()->orderBy('store_number')->get();

        if ($stores->isEmpty()) {
            $this->error($storeNumber !== null ? "Store {$storeNumber} not found." : 'No stores exist yet.');

            return self::FAILURE;
        }

        $positions = Position::query()->orderBy('label')->get();
        $force = (bool) $this->option('force');

        $matched = 0;
        $unmapped = 0;
        $ambiguous = [];
        $conflicts = 0;
        $skippedStores = 0;

        foreach ($stores as $store) {
            $candidates = $this->activeHumanityPositions($store);

            if ($candidates === null) {
                $this->warn("  {$store->store_number}: no Humanity location mapped — skipped.");
                $skippedStores++;

                continue;
            }

            if ($candidates->isEmpty()) {
                $this->warn("  {$store->store_number}: no active Humanity positions for this location — skipped.");
                $skippedStores++;

                continue;
            }

            $humanityLocationId = HumanityLocation::query()
                ->where('store_id', $store->id)
                ->value('humanity_location_id');

            foreach ($positions as $position) {
                $label = (string) $position->label;

                $storeScoped = $candidates->filter(
                    fn (HumanityPosition $p) => $p->humanity_location_id === $humanityLocationId
                        && stripos($p->name, $label) === 0
                );

                $matches = $storeScoped->isNotEmpty() ? $storeScoped : $candidates->filter(
                    fn (HumanityPosition $p) => $p->humanity_location_id === null
                        && stripos($p->name, $label) === 0
                );

                if ($matches->isEmpty()) {
                    $unmapped++;

                    continue;
                }

                if ($matches->count() > 1) {
                    $ambiguous[] = sprintf(
                        '%s / %s: %s',
                        $store->store_number,
                        $label,
                        $matches->map(fn (HumanityPosition $p) => "{$p->humanity_position_id} ({$p->name})")->implode(', ')
                    );

                    continue;
                }

                $match = $matches->first();

                $existing = HumanityPositionMap::query()
                    ->where('store_id', $store->id)
                    ->where('position_id', $position->id)
                    ->first();

                if ($existing !== null && $existing->humanity_position_id !== $match->humanity_position_id && !$force) {
                    $this->warn(sprintf(
                        '  %s / %s: already mapped to %s (auto-match is %s) — left unchanged, pass --force to overwrite.',
                        $store->store_number,
                        $label,
                        $existing->humanity_position_id,
                        $match->humanity_position_id
                    ));
                    $conflicts++;

                    continue;
                }

                HumanityPositionMap::query()->updateOrCreate(
                    ['store_id' => $store->id, 'position_id' => $position->id],
                    ['humanity_position_id' => $match->humanity_position_id, 'is_default' => false]
                );

                $matched++;
            }
        }

        $this->table(['metric', 'count'], [
            ['positions matched', $matched],
            ['positions unmapped (no match)', $unmapped],
            ['positions ambiguous', count($ambiguous)],
            ['existing mappings left unchanged (conflict)', $conflicts],
            ['stores skipped (no location / no positions)', $skippedStores],
        ]);

        if ($ambiguous !== []) {
            $this->newLine();
            $this->error('Ambiguous — resolve by hand with --position/--humanity-position:');

            foreach ($ambiguous as $line) {
                $this->line("  {$line}");
            }
        }

        return $ambiguous === [] ? self::SUCCESS : self::FAILURE;
    }

    private function list(Store $store): int
    {
        $rows = HumanityPositionMap::query()->where('store_id', $store->id)->get();
        $positions = Position::query()->pluck('label', 'id');
        $humanityNames = HumanityPosition::query()->pluck('name', 'humanity_position_id');

        if ($rows->isEmpty()) {
            $this->warn("Store {$store->store_number} has NO position mappings.");
            $this->line('Every shift creation will fail with POSITION_NOT_MAPPED. Start with:');
            $this->line("  php artisan humanity:map-position --store={$store->store_number} --default");

            return self::SUCCESS;
        }

        $this->table(
            ['our position', 'humanity_position_id', 'humanity name', 'default'],
            $rows->map(fn (HumanityPositionMap $row) => [
                $row->position_id === null ? '(store default)' : ($positions[$row->position_id] ?? "#{$row->position_id}"),
                $row->humanity_position_id,
                $humanityNames[$row->humanity_position_id] ?? '—',
                $row->is_default ? 'yes' : '',
            ])->all()
        );

        if (!$rows->contains(fn (HumanityPositionMap $row) => $row->position_id === null)) {
            $this->newLine();
            $this->warn('No default row. An employee whose position is unmapped cannot be scheduled.');
        }

        $unmapped = Position::query()
            ->whereNotIn('id', $rows->pluck('position_id')->filter()->all())
            ->pluck('label', 'id');

        if ($unmapped->isNotEmpty()) {
            $this->newLine();
            $this->line('<info>Our positions with no mapping (they fall back to the default)</info>');
            foreach ($unmapped as $id => $label) {
                $this->line("  {$id}  {$label}");
            }
        }

        return self::SUCCESS;
    }

    private function unmap(Store $store): int
    {
        $positionId = $this->resolvePositionId();
        if ($positionId === false) {
            return self::FAILURE;
        }

        $deleted = HumanityPositionMap::query()
            ->where('store_id', $store->id)
            ->where(fn ($q) => $positionId === null ? $q->whereNull('position_id') : $q->where('position_id', $positionId))
            ->delete();

        if ($deleted === 0) {
            $this->warn('No such mapping.');

            return self::SUCCESS;
        }

        if ($positionId === null) {
            $this->warn('Removed the DEFAULT mapping. Employees with no mapped position can no longer be scheduled.');
        } else {
            $this->info('Mapping removed; that position now falls back to the store default.');
        }

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
            $this->error("Store {$storeNumber} not found.");

            return null;
        }

        return $store;
    }

    /** @return int|null|false  null = the store default; false = the caller should abort */
    private function resolvePositionId(): int|null|false
    {
        if ($this->option('default')) {
            return null;
        }

        $positionId = $this->option('position');

        if ($positionId !== null) {
            if (Position::query()->find((int) $positionId) === null) {
                $this->error("Position {$positionId} does not exist. Positions replicate from HiringPizza inside employee snapshots.");

                return false;
            }

            return (int) $positionId;
        }

        $positions = Position::query()->orderBy('label')->pluck('label', 'id');

        $choices = ['(store default — used when an employee has no mapped position)'];
        $ids = [null];

        foreach ($positions as $id => $label) {
            $choices[] = "{$id}  {$label}";
            $ids[] = (int) $id;
        }

        $picked = $this->choice('Map which position?', $choices);

        return $ids[array_search($picked, $choices, true)];
    }

    private function resolveHumanityPositionId(Store $store): ?string
    {
        $candidates = $this->activeHumanityPositions($store);

        if ($candidates === null) {
            $this->error("Store {$store->store_number} is not mapped to a Humanity location yet.");
            $this->line("  php artisan humanity:map-location --store={$store->store_number}");

            return null;
        }

        if ($candidates->isEmpty()) {
            $this->error('No Humanity positions found for this location.');
            $this->line('  php artisan humanity:sync-catalog');

            return null;
        }

        $given = $this->option('humanity-position');

        if ($given !== null) {
            if (!$candidates->contains(fn (HumanityPosition $p) => $p->humanity_position_id === $given)) {
                $this->error("Humanity position {$given} is not available for this store's location.");
                $this->line('Run with --list, or humanity:sync-catalog if it is new.');

                return null;
            }

            return $given;
        }

        $labels = $candidates->map(fn (HumanityPosition $p) => "{$p->humanity_position_id}  {$p->name}")->all();

        return strtok($this->choice('Which Humanity position?', $labels), ' ');
    }

    /**
     * Active Humanity positions available to a store: ones scoped to its own
     * location, plus location-less (global) ones. Null means the store has no
     * Humanity location mapped at all — distinct from an empty result, which
     * means the location exists but the catalog has no active positions for it.
     *
     * @return Collection<int, HumanityPosition>|null
     */
    private function activeHumanityPositions(Store $store): ?Collection
    {
        $humanityLocationId = HumanityLocation::query()
            ->where('store_id', $store->id)
            ->value('humanity_location_id');

        if ($humanityLocationId === null) {
            return null;
        }

        // Only positions belonging to THIS store's location — a shift written
        // against another location's position would land in the wrong
        // restaurant. Positions with no location are global, so allow those.
        return HumanityPosition::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('humanity_location_id', $humanityLocationId)->orWhereNull('humanity_location_id'))
            ->orderBy('name')
            ->get();
    }
}
