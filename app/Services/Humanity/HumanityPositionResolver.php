<?php

namespace App\Services\Humanity;

use App\Models\Employee;
use App\Models\HumanityLocation;
use App\Models\HumanityPositionMap;
use App\Models\Store;
use App\Services\Scheduling\Exceptions\SchedulingException;

/**
 * The single seam for "which Humanity container does this shift belong to".
 *
 * Humanity's model is Location -> Position -> Shift, and its shift API spells
 * Position as the `schedule` parameter. If that ever turns out to work
 * differently, this class is what changes.
 */
class HumanityPositionResolver
{
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
     * Keyed on the label TEXT, not a position id: we deliberately do not
     * replicate HiringPizza's position catalog, and Humanity's own positions
     * are named by the same store-suffixed convention anyway.
     *
     * Local reads only — never calls Humanity.
     */
    public function positionId(Store $store, ?Employee $employee = null, ?string $label = null): string
    {
        $candidates = array_values(array_filter([
            $label,
            $employee?->position_label,
        ], fn ($value) => filled($value)));

        foreach ($candidates as $candidate) {
            $mapped = HumanityPositionMap::query()
                ->where('store_id', $store->id)
                ->where('position_label', $candidate)
                ->value('humanity_position_id');

            if (filled($mapped)) {
                return (string) $mapped;
            }
        }

        $default = HumanityPositionMap::query()
            ->where('store_id', $store->id)
            ->whereNull('position_label')
            ->value('humanity_position_id');

        if (filled($default)) {
            return (string) $default;
        }

        throw SchedulingException::positionNotMapped((string) $store->store_number, $label);
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
