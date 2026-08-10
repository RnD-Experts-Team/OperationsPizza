<?php

namespace App\Console\Commands;

use App\Models\HumanityLocation;
use App\Models\HumanityPosition;
use App\Models\HumanityPositionMap;
use App\Models\Position;
use App\Models\Store;
use Illuminate\Console\Command;

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
        {--store= : store_number, e.g. 03759-00001}
        {--humanity-position= : Humanity position id}
        {--position= : our positions.id; omit together with --default}
        {--default : Map the store default (position_id NULL) used when an employee has no mapped position}
        {--list : Show this store\'s position mappings}
        {--unmap : Remove the mapping identified by --position/--default}';

    protected $description = 'Map a position to its Humanity position for a store';

    public function handle(): int
    {
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
        $humanityLocationId = HumanityLocation::query()
            ->where('store_id', $store->id)
            ->value('humanity_location_id');

        if ($humanityLocationId === null) {
            $this->error("Store {$store->store_number} is not mapped to a Humanity location yet.");
            $this->line("  php artisan humanity:map-location --store={$store->store_number}");

            return null;
        }

        // Only positions belonging to THIS store's location — a shift written
        // against another location's position would land in the wrong
        // restaurant. Positions with no location are global, so allow those.
        $candidates = HumanityPosition::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('humanity_location_id', $humanityLocationId)->orWhereNull('humanity_location_id'))
            ->orderBy('name')
            ->get();

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
}
