<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\Store;
use App\Services\EventConsume\EventHandlerInterface;
use Illuminate\Support\Facades\DB;

class StoreCreatedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $store = data_get($event, 'data.store');

        if (!is_array($store)) {
            throw new \Exception('StoreCreatedHandler: missing store payload');
        }

        $id = $this->asInt(data_get($store, 'id'));
        $name = data_get($store, 'name');

        if ($id <= 0) {
            throw new \Exception('StoreCreatedHandler: invalid store id');
        }

        DB::transaction(function () use ($id, $name) {
            Store::query()->updateOrCreate(
                ['id' => $id],
                [
                    'store' => (string) $name,
                ]
            );
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