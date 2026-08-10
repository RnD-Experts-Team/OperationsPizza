<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\Store;
use App\Services\EventConsume\EventHandlerInterface;
use App\Services\EventConsume\Handlers\Concerns\ReplicatesStores;
use Illuminate\Support\Facades\DB;

class StoreCreatedHandler implements EventHandlerInterface
{
    use ReplicatesStores;

    public function handle(array $event): void
    {
        $storePayload = $this->extractStorePayload($event);

        $id = $this->resolveStorePk($event, $storePayload);
        if ($id <= 0) {
            throw new \Exception('StoreCreatedHandler: missing/invalid store.id');
        }

        // stores.store_number is pizzasys' external `store_id` string, NOT the
        // display name — it is the {storeId} segment in our API routes.
        $storeNumber = $this->extractStoreNumber($storePayload);
        if ($storeNumber === null) {
            throw new \Exception("StoreCreatedHandler: missing store_id (store number) for store {$id}");
        }

        $name = $this->stringOrNull(data_get($storePayload, 'name'));

        // NOTE: pizzasys also sends is_active and (sometimes) a metadata
        // timezone. We deliberately store neither.
        //   - is_active: an inactive store simply stops receiving traffic;
        //     nothing here branches on it, and a stale copy would only ever
        //     disagree with pizzasys.
        //   - timezone: sourced from the mapped Humanity location instead
        //     (see StoreTimezoneResolver), because that is the timezone
        //     Humanity actually interprets our shift times in.
        DB::transaction(function () use ($id, $storeNumber, $name) {
            $store = Store::withTrashed()->find($id);

            if ($store === null) {
                Store::query()->create([
                    'id' => $id,
                    'store_number' => $storeNumber,
                    'name' => $name,
                ]);

                return;
            }

            // Redelivery or a re-created store: refresh identity.
            $store->fill([
                'store_number' => $storeNumber,
                'name' => $name,
            ]);

            if ($store->trashed()) {
                $store->restore();
            }

            $store->save();
        });
    }
}
