<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Store;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

trait ResolvesStore
{
    /**
     * Routes carry the store_number string ("03759-00001"), matching
     * HiringPizza — NOT the numeric pk that NATS events use.
     */
    protected function resolveStore(string $storeNumber): Store
    {
        $store = Store::query()->where('store_number', $storeNumber)->first();

        if ($store === null) {
            throw new NotFoundHttpException("Store {$storeNumber} not found.");
        }

        return $store;
    }
}
