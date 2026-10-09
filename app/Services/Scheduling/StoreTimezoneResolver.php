<?php

namespace App\Services\Scheduling;

use App\Models\HumanityLocation;
use App\Models\Store;

/**
 * The timezone a store's shifts are written in.
 *
 * Sourced from the store's mapped Humanity location rather than a local column,
 * because that is the timezone that actually decides what a shift means:
 * Humanity has no per-request timezone parameter and interprets every date and
 * "HH:MM" we send in its own location's local time. Keeping a second copy
 * locally would only create something that can silently drift from the value
 * Humanity is really using.
 *
 * Falls back to operations.default_timezone for a store that isn't mapped yet
 * (or a Humanity location that reports none).
 */
class StoreTimezoneResolver
{
    /** @var array<int, string> */
    private array $cache = [];

    public function for(Store $store): string
    {
        $storeId = (int) $store->id;

        if (isset($this->cache[$storeId])) {
            return $this->cache[$storeId];
        }

        $timezone = HumanityLocation::query()
            ->where('store_id', $storeId)
            ->value('timezone');

        if (!$this->isValid($timezone)) {
            $timezone = (string) config('operations.default_timezone', 'UTC');
        }

        return $this->cache[$storeId] = (string) $timezone;
    }

    /**
     * A bad identifier would make CarbonImmutable throw deep inside a shift
     * write, so it is rejected here in favour of the configured default.
     */
    private function isValid(mixed $timezone): bool
    {
        if (!is_string($timezone) || $timezone === '') {
            return false;
        }

        return in_array($timezone, timezone_identifiers_list(), true);
    }
}
