<?php

namespace App\Models\Concerns;

/**
 * For models this service does NOT own — their rows arrive over NATS from the
 * source-of-truth service and carry that service's primary key.
 *
 * The id is externally supplied, so auto-increment must be off and `id` must be
 * mass-assignable (handlers do updateOrCreate(['id' => $id], [...])).
 */
trait ReplicatedModel
{
    public function initializeReplicatedModel(): void
    {
        $this->incrementing = false;
        $this->keyType = 'int';
    }
}
