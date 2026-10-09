<?php

namespace App\Services\Humanity;

use App\Models\HumanitySyncLog;
use Throwable;

/**
 * Writes the audit row BEFORE the call, which is what makes a timed-out write
 * recoverable: a row still `pending` on the next attempt means "we don't know
 * whether Humanity received this", and the caller must probe rather than
 * blindly retry (Humanity has no idempotency key for shifts).
 *
 * Deliberately records only what someone actually reads back — either in code
 * (pendingFor, below) or by hand during an incident. Request/response blobs,
 * timings and transport details were dropped: on a table that gets a row per
 * external call, unread columns are the bulk of the storage.
 */
class HumanitySyncLogger
{
    public function begin(
        string $entityType,
        string $operation,
        ?int $entityId = null,
        ?int $storeId = null,
        ?string $humanityId = null,
    ): HumanitySyncLog {
        return HumanitySyncLog::query()->create([
            'store_id' => $storeId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'humanity_id' => $humanityId,
            'operation' => $operation,
            'status' => 'pending',
        ]);
    }

    public function succeeded(HumanitySyncLog $log, ?string $humanityId = null): void
    {
        $log->update([
            'status' => 'succeeded',
            'humanity_id' => $humanityId ?? $log->humanity_id,
            'error_message' => null,
        ]);
    }

    public function failed(HumanitySyncLog $log, Throwable $e): void
    {
        $log->update([
            'status' => 'failed',
            // The message carries the classification anyway — HumanityException
            // renders its own status/code into the text it throws with.
            'error_message' => $e->getMessage(),
        ]);
    }

    public function recordReconciliation(
        string $entityType,
        int $entityId,
        ?string $humanityId,
        string $outcome,
        array $diff = [],
        ?int $storeId = null,
    ): HumanitySyncLog {
        return HumanitySyncLog::query()->create([
            'store_id' => $storeId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'humanity_id' => $humanityId,
            'operation' => 'reconcile',
            'status' => 'succeeded',
            // The outcome used to be stuffed into error_code, which meant a
            // successful reconcile carried something named like a failure.
            'diff' => ['outcome' => $outcome] + ($diff ?: []),
        ]);
    }

    /**
     * A previous attempt for this entity that never reached a verdict. Its
     * existence is the signal to probe Humanity before creating anything.
     */
    public function pendingFor(string $entityType, int $entityId): ?HumanitySyncLog
    {
        return HumanitySyncLog::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('status', 'pending')
            ->latest('created_at')
            ->first();
    }
}
