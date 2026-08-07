<?php

namespace App\Services\Humanity;

use App\Models\HumanityDeadLetter;
use App\Models\HumanitySyncLog;
use App\Services\Humanity\Exceptions\HumanityException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes the audit row BEFORE the call, which is what makes a timed-out write
 * recoverable: a row still `pending` on the next attempt means "we don't know
 * whether Humanity received this", and the caller must probe rather than
 * blindly retry (Humanity has no idempotency key for shifts).
 */
class HumanitySyncLogger
{
    public function begin(
        string $entityType,
        string $operation,
        ?int $entityId = null,
        ?int $storeId = null,
        ?string $humanityId = null,
        array $requestPayload = [],
        ?string $correlationId = null,
    ): HumanitySyncLog {
        return HumanitySyncLog::query()->create([
            'store_id' => $storeId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'humanity_id' => $humanityId,
            'operation' => $operation,
            'idempotency_key' => (string) Str::ulid(),
            'status' => 'pending',
            'request_payload' => $requestPayload ?: null,
            'correlation_id' => $correlationId,
            'attempts' => 1,
        ]);
    }

    public function succeeded(HumanitySyncLog $log, ?string $humanityId = null, array $response = [], ?int $durationMs = null): void
    {
        $log->update([
            'status' => 'succeeded',
            'humanity_id' => $humanityId ?? $log->humanity_id,
            'response_payload' => $response ?: null,
            'duration_ms' => $durationMs,
            'error_code' => null,
            'error_message' => null,
        ]);
    }

    public function failed(HumanitySyncLog $log, Throwable $e, ?int $durationMs = null): void
    {
        $log->update([
            'status' => 'failed',
            'humanity_status' => $e instanceof HumanityException ? $e->humanityStatus : null,
            'http_status' => $e instanceof HumanityException ? $e->httpStatus : null,
            'error_code' => $e instanceof HumanityException ? $e->errorCode() : class_basename($e),
            'error_message' => $e->getMessage(),
            'duration_ms' => $durationMs,
        ]);
    }

    /** Give up on this one and leave it somewhere a human will find it. */
    public function park(HumanitySyncLog $log, Throwable $e): HumanityDeadLetter
    {
        $this->failed($log, $e);

        return HumanityDeadLetter::query()->create([
            'humanity_sync_log_id' => $log->id,
            'store_id' => $log->store_id,
            'entity_type' => $log->entity_type,
            'entity_id' => $log->entity_id,
            'operation' => $log->operation,
            'payload' => $log->request_payload,
            'error_code' => $e instanceof HumanityException ? $e->errorCode() : class_basename($e),
            'error_message' => $e->getMessage(),
            'attempts' => $log->attempts,
            'parked_at' => now(),
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
            'idempotency_key' => (string) Str::ulid(),
            'status' => 'succeeded',
            'diff' => $diff ?: null,
            'error_code' => $outcome,
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
