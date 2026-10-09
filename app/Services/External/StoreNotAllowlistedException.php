<?php

namespace App\Services\External;

use App\Services\Scheduling\Exceptions\SchedulingException;

/**
 * This store is not in the external-write rollout allowlist.
 *
 * Extends SchedulingException so it renders like every other domain failure —
 * same shape as EmployeeNotInTcpException, and picked up by the generic
 * SchedulingException branch in SchedulingExceptionRenderer rather than needing
 * one of its own. Before that it was a bare RuntimeException and surfaced as a
 * 500, which read as "the service is broken" when the truth is "this guard did
 * exactly its job".
 *
 * 403, not 422: nothing about the request is wrong. The same call succeeds
 * unchanged once the store is added to the allowlist.
 */
class StoreNotAllowlistedException extends SchedulingException
{
    public function __construct(public readonly string $storeNumber)
    {
        parent::__construct(
            "External writes for store {$storeNumber} are blocked: it is not in EXTERNAL_WRITE_ALLOWED_STORES. "
                . 'This guard exists because TCP and Humanity have no sandbox — widen the allowlist deliberately, per store.',
            'STORE_NOT_ALLOWLISTED',
            403,
            [
                'store_number' => $storeNumber,
                'resolution' => 'Add this store to EXTERNAL_WRITE_ALLOWED_STORES once it is ready to write to the live vendor accounts.',
            ],
        );
    }
}
