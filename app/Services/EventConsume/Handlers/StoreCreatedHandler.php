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
        $isActive = data_get($storePayload, 'is_active');
        $timezone = $this->resolveTimezone(data_get($storePayload, 'metadata'), $storeNumber);

        DB::transaction(function () use ($id, $storeNumber, $name, $isActive, $timezone) {
            $store = Store::withTrashed()->find($id);

            if ($store === null) {
                Store::query()->create([
                    'id' => $id,
                    'store_number' => $storeNumber,
                    'name' => $name,
                    'timezone' => $timezone,
                    'is_active' => $isActive === null ? true : (bool) $isActive,
                ]);

                return;
            }

            // Redelivery or a re-created store: refresh identity but never
            // clobber a timezone an operator may have corrected by hand — the
            // event is not authoritative for it (see ReplicatesStores).
            $store->fill([
                'store_number' => $storeNumber,
                'name' => $name,
                'is_active' => $isActive === null ? true : (bool) $isActive,
            ]);

            if ($store->trashed()) {
                $store->restore();
            }

            $store->save();
        });
    }
}
