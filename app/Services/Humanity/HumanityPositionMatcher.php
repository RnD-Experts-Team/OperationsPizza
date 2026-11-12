<?php

namespace App\Services\Humanity;

use App\Models\HumanityLocation;
use App\Models\HumanityPosition;
use App\Models\Store;
use Illuminate\Support\Collection;

/**
 * Which Humanity position a position label belongs to at a store, by name:
 * Humanity names its positions "<label> - <store code>" ("Crew Member -
 * 3795-12"), so the match is a position whose name starts with the label.
 *
 * Shared by `humanity:map-position --auto-map` and HumanityPositionResolver,
 * so a label is matched the same way whether it is mapped ahead of time or the
 * first time a shift needs it. Local reads only — never calls Humanity.
 */
class HumanityPositionMatcher
{
    /**
     * Active positions this store may schedule into: its own location's, plus
     * global ones (no location). Null when the store has no Humanity location.
     *
     * @return Collection<int, HumanityPosition>|null
     */
    public function positionsFor(Store $store): ?Collection
    {
        $locationId = $this->locationId($store);

        if ($locationId === null) {
            return null;
        }

        // Only positions belonging to THIS store's location — a shift written
        // against another location's position would land in the wrong
        // restaurant. Positions with no location are global, so allow those.
        return HumanityPosition::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('humanity_location_id', $locationId)->orWhereNull('humanity_location_id'))
            ->orderBy('name')
            ->get();
    }

    /**
     * Every best match for one label. One element is a clean match, more than
     * one is ambiguous, none is no match.
     *
     * Store-scoped positions are tried before global ones: the real catalog has
     * both a bare "Crew Member" and per-store "Crew Member - 3795-23" for the
     * same role. Among several store-scoped matches, the one named for THIS
     * store wins — a location can also hold another store's position by
     * mistake ("Crew Member - 3795-08" under store 14).
     *
     * @param  Collection<int, HumanityPosition>|null  $positions  from positionsFor(), to avoid re-reading per label
     * @return Collection<int, HumanityPosition>
     */
    public function matches(Store $store, string $label, ?Collection $positions = null): Collection
    {
        $positions ??= $this->positionsFor($store);

        if ($positions === null || trim($label) === '') {
            return collect();
        }

        $locationId = $this->locationId($store);
        $startsWith = fn (HumanityPosition $p) => stripos($p->name, $label) === 0;

        foreach ([
            $positions->filter(fn (HumanityPosition $p) => $p->humanity_location_id === $locationId && $startsWith($p)),
            $positions->filter(fn (HumanityPosition $p) => $p->humanity_location_id === null && $startsWith($p)),
        ] as $tier) {
            if ($tier->isEmpty()) {
                continue;
            }

            if ($tier->count() > 1) {
                $code = $this->storeCode($store);
                $named = $code === null
                    ? collect()
                    : $tier->filter(fn (HumanityPosition $p) => str_ends_with(strtolower(trim($p->name)), strtolower($code)));

                if ($named->count() === 1) {
                    return $named->values();
                }
            }

            return $tier->values();
        }

        return collect();
    }

    /** The single match for a label, or null when there is none or it is ambiguous. */
    public function match(Store $store, string $label, ?Collection $positions = null): ?HumanityPosition
    {
        $matches = $this->matches($store, $label, $positions);

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * "03795-00012" -> "3795-12", the store code Humanity and TCP put at the end
     * of a position name. Null for a store number not in that shape.
     */
    public function storeCode(Store $store): ?string
    {
        if (!preg_match('/^0*(\d+)-0*(\d+)$/', (string) $store->store_number, $m)) {
            return null;
        }

        return $m[1] . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT);
    }

    private function locationId(Store $store): ?string
    {
        $id = HumanityLocation::query()->where('store_id', $store->id)->value('humanity_location_id');

        return $id === null ? null : (string) $id;
    }
}
