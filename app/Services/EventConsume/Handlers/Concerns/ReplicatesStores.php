<?php

namespace App\Services\EventConsume\Handlers\Concerns;


/**
 * Shared parsing for pizzasys store events.
 *
 * `auth.v1.store.created` carries a full snapshot at data.store:
 *   {id, store_id, name, metadata, is_active, created_at, updated_at}
 *
 * `auth.v1.store.updated` carries only deltas:
 *   {store_id: <int pk>, changed_fields: {name|metadata|is_active: {from,to}}, updated_at}
 *
 * Note the name collision: on `created`, `store_id` is the external STRING
 * ("03759-00001"); on `updated`, `store_id` is the integer PK. They are
 * resolved separately below — do not merge these two lookups.
 */
trait ReplicatesStores
{
    protected function extractStorePayload(array $event): array
    {
        foreach (['data.store', 'store', 'payload.store'] as $path) {
            $store = data_get($event, $path);
            if (is_array($store)) {
                return $store;
            }
        }

        return [];
    }

    /** The integer primary key, however this particular event spells it. */
    protected function resolveStorePk(array $event, array $storePayload): int
    {
        $id = $this->asInt(data_get($storePayload, 'id'));
        if ($id > 0) {
            return $id;
        }

        // On `updated`/`deleted`, data.store_id IS the integer pk.
        return $this->asInt(
            data_get($event, 'data.store_id')
            ?? data_get($event, 'store_id')
        );
    }

    /** The external store number string, e.g. "03759-00001". */
    protected function extractStoreNumber(array $storePayload): ?string
    {
        // pizzasys calls it store_id; prefer that.
        $value = $this->stringOrNull(data_get($storePayload, 'store_id'));
        if ($value !== null) {
            return $value;
        }

        return $this->stringOrNull(data_get($storePayload, 'store_number'));
    }

    /**
     * NOTE: pizzasys store events carry no timezone at all — its stores table
     * has no such column (verified in StoreManagementService::createStore),
     * only a free-form `metadata` blob. We no longer try to read one: the
     * timezone that decides what a shift means is the one on the mapped
     * Humanity location, because Humanity interprets every date and "HH:MM" we
     * send in its own location local time. See StoreTimezoneResolver.
     */

    protected function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    protected function asInt(mixed $v): int
    {
        if (is_int($v)) {
            return $v;
        }

        if (is_string($v) && ctype_digit($v)) {
            return (int) $v;
        }

        if (is_numeric($v)) {
            return (int) $v;
        }

        return 0;
    }
}
