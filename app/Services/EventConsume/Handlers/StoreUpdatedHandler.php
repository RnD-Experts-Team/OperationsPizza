<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\Store;
use App\Services\EventConsume\EventHandlerInterface;
use App\Services\EventConsume\Handlers\Concerns\ReplicatesStores;
use Illuminate\Support\Facades\DB;

/**
 * `auth.v1.store.updated` carries deltas only:
 *   {store_id: <int pk>, changed_fields: {name|metadata|is_active: {from,to}}}
 * pizzasys tracks exactly those three fields, so store_number never changes.
 */
class StoreUpdatedHandler implements EventHandlerInterface
{
    use ReplicatesStores;

    public function handle(array $event): void
    {
        $id = $this->resolveStorePk($event, $this->extractStorePayload($event));
        if ($id <= 0) {
            throw new \Exception('StoreUpdatedHandler: missing/invalid store id');
        }

        $changed = data_get($event, 'data.changed_fields');
        $changed = is_array($changed) ? $changed : [];

        DB::transaction(function () use ($id, $changed) {
            $store = Store::withTrashed()->lockForUpdate()->find($id);

            // Ordering is not guaranteed across redeliveries, so an update can
            // land before its create. Throwing lets JetStreamConsumer NAK and
            // retry rather than materialising a store with no store_number.
            if ($store === null) {
                throw new \Exception("StoreUpdatedHandler: store {$id} not synced yet");
            }

            $update = [];

            if (array_key_exists('name', $changed)) {
                $update['name'] = $this->stringOrNull(data_get($changed, 'name.to'));
            }

            // is_active and metadata.timezone are intentionally ignored — we
            // store neither. See StoreCreatedHandler for why, and
            // StoreTimezoneResolver for where the timezone comes from instead.

            if ($update !== []) {
                $store->update($update);
            }
        });
    }
}
