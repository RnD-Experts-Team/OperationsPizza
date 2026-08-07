<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\Store;
use App\Services\EventConsume\EventHandlerInterface;
use Illuminate\Support\Facades\DB;

class StoreDeletedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $storeId = $this->asInt(
            data_get($event, 'data.store_id')
                ?? data_get($event, 'store_id')
                ?? data_get($event, 'data.store.id')
                ?? data_get($event, 'store.id')
        );

        if ($storeId <= 0) {
            throw new \Exception('StoreDeletedHandler: missing/invalid store_id');
        }

        DB::transaction(function () use ($storeId) {
            Store::query()->where('id', $storeId)->delete();
        });
    }

    private function asInt(mixed $v): int
    {
        if (is_int($v)) return $v;
        if (is_string($v) && ctype_digit($v)) return (int) $v;
        if (is_numeric($v)) return (int) $v;
        return 0;
    }
}
