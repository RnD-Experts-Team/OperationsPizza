<?php

namespace App\Services\EventConsume\Handlers\Concerns;

use Illuminate\Support\Facades\Log;

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
     * Store events do NOT carry a timezone — pizzasys' stores table has no such
     * column (verified in StoreManagementService::createStore). It only has a
     * free-form `metadata` JSON blob, so that is the one place an operator can
     * put one.
     *
     * Every shift time in this service is stored as a UTC instant derived from
     * the store's local wall-clock time. A wrong timezone here is therefore
     * silent, week-long corruption that nobody notices until payroll — so the
     * fallback is logged at warning level, deliberately noisily.
     */
    protected function resolveTimezone(mixed $metadata, string $storeNumber): string
    {
        $fromMetadata = $this->timezoneFromMetadata($metadata, $storeNumber);
        if ($fromMetadata !== null) {
            return $fromMetadata;
        }

        $configured = config("operations.store_timezones.{$storeNumber}");
        if (is_string($configured) && $this->isValidTimezone($configured)) {
            return $configured;
        }

        $default = (string) config('operations.default_timezone', 'America/Chicago');

        Log::warning('Store has no timezone in event metadata or config — falling back', [
            'store_number' => $storeNumber,
            'fallback' => $default,
            'impact' => 'every shift time for this store will be converted using the fallback timezone',
        ]);

        return $default;
    }

    /**
     * The timezone an operator actually put in pizzasys' store metadata, or
     * null if there isn't one. Distinct from resolveTimezone(), which always
     * returns something — callers applying a partial update need to know the
     * difference between "metadata says UTC" and "metadata said nothing", so
     * they don't overwrite a good stored value with the app default.
     */
    protected function timezoneFromMetadata(mixed $metadata, string $storeNumber): ?string
    {
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : null;
        }

        if (!is_array($metadata)) {
            return null;
        }

        foreach (['timezone', 'time_zone', 'tz'] as $key) {
            $tz = $this->stringOrNull(data_get($metadata, $key));

            if ($tz === null) {
                continue;
            }

            if ($this->isValidTimezone($tz)) {
                return $tz;
            }

            Log::warning('Store metadata carries an invalid timezone; ignoring', [
                'store_number' => $storeNumber,
                'value' => $tz,
            ]);
        }

        return null;
    }

    private function isValidTimezone(string $tz): bool
    {
        return in_array($tz, timezone_identifiers_list(), true);
    }

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
