<?php

namespace App\Services\Humanity;

use App\Models\Employee;
use App\Models\HumanityLocation;
use App\Models\HumanityPositionMap;
use App\Models\Store;
use App\Services\Scheduling\Exceptions\SchedulingException;
use App\Support\PositionLabel;
use Illuminate\Support\Facades\Log;

/**
 * The single seam for "which Humanity container does this shift belong to".
 *
 * Humanity's model is Location -> Position -> Shift, and its shift API spells
 * Position as the `schedule` parameter. If that ever turns out to work
 * differently, this class is what changes.
 */
class HumanityPositionResolver
{
    public function __construct(private readonly HumanityPositionMatcher $matcher)
    {
    }

    public function locationId(Store $store): string
    {
        $locationId = HumanityLocation::query()
            ->where('store_id', $store->id)
            ->value('humanity_location_id');

        if (blank($locationId)) {
            throw SchedulingException::storeNotMapped((string) $store->store_number);
        }

        return (string) $locationId;
    }

    /**
     * Resolve by explicit label, else the employee's own position label, else
     * the store's default row (position_label IS NULL).
     *
     * Each label is tried as-is and then without its variant suffix
     * ("Crew Member - AT" -> "Crew Member"; see App\Support\PositionLabel).
     * For each of those names a saved mapping wins; with none, the name is
     * matched against the local Humanity position mirror the same way
     * `humanity:map-position --auto-map` does, and a clean match is saved —
     * so a label nobody held when the store was last auto-mapped still
     * schedules the first time it is needed. An ambiguous or missing match
     * saves nothing and falls through.
     *
     * Keyed on the label TEXT, not a position id: we deliberately do not
     * replicate HiringPizza's position catalog, and Humanity's own positions
     * are named by the same store-suffixed convention anyway.
     *
     * Local reads (and that one local write) only — never calls Humanity.
     */
    public function positionId(Store $store, ?Employee $employee = null, ?string $label = null): string
    {
        $names = [];

        foreach ([$label, $employee?->position_label] as $candidate) {
            foreach (PositionLabel::candidates($candidate) as $name) {
                $names[] = $name;
            }
        }

        $positions = null;

        foreach (array_values(array_unique($names)) as $name) {
            $mapped = HumanityPositionMap::query()
                ->where('store_id', $store->id)
                ->where('position_label', $name)
                ->value('humanity_position_id');

            if (filled($mapped)) {
                return (string) $mapped;
            }

            $positions ??= $this->matcher->positionsFor($store) ?? collect();
            $match = $this->matcher->match($store, $name, $positions);

            if ($match !== null) {
                HumanityPositionMap::query()->firstOrCreate(
                    ['store_id' => $store->id, 'position_label' => $name],
                    ['humanity_position_id' => $match->humanity_position_id, 'is_default' => false]
                );

                Log::info('Mapped a position label to Humanity on first use', [
                    'store_number' => $store->store_number,
                    'position_label' => $name,
                    'humanity_position_id' => $match->humanity_position_id,
                ]);

                return (string) $match->humanity_position_id;
            }
        }

        $default = HumanityPositionMap::query()
            ->where('store_id', $store->id)
            ->whereNull('position_label')
            ->value('humanity_position_id');

        if (filled($default)) {
            return (string) $default;
        }

        throw SchedulingException::positionNotMapped((string) $store->store_number, $label ?? $employee?->position_label);
    }

    /** Positions this store can schedule into — the UI's department list. */
    public function mappedPositionIds(Store $store): array
    {
        return HumanityPositionMap::query()
            ->where('store_id', $store->id)
            ->pluck('humanity_position_id')
            ->unique()
            ->values()
            ->all();
    }
}
