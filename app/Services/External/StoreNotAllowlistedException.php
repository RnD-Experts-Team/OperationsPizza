<?php

namespace App\Services\External;

use RuntimeException;

class StoreNotAllowlistedException extends RuntimeException
{
    public function __construct(public readonly string $storeNumber)
    {
        parent::__construct(
            "External writes for store {$storeNumber} are blocked: it is not in EXTERNAL_WRITE_ALLOWED_STORES. "
                . 'This guard exists because TCP and Humanity have no sandbox — widen the allowlist deliberately, per store.'
        );
    }
}
