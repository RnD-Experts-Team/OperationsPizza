<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\Store;
use App\Services\EventConsume\EventHandlerInterface;
use Illuminate\Support\Facades\DB;

class StoreUpdatedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $id = $this->asInt(
            data_get($event, 'data.store_id')
            ?? data_get($event, 'store_id')
        );

        if ($id <= 0) {
            throw new \Exception('StoreUpdatedHandler: invalid store id');
        }

        $changed = data_get($event, 'data.changed_fields', []);

        $name = data_get($changed, 'name.to');

        if ($name === null) {
            // nothing to update
            return;
        }

        DB::transaction(function () use ($id, $name) {
            $store = Store::query()->find($id);

            if (!$store) {
                // optional: skip or create
                return;
            }

            $store->update([
                'store' => (string) $name,
            ]);
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