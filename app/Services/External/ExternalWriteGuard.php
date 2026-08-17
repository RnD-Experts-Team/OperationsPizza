<?php

namespace App\Services\External;

/**
 * The store allowlist for writes to TCP and Humanity (production-only vendors
 * — no sandbox exists for either).
 *
 * Enforced at the service layer, where the store is known, on every mutating
 * path: shift writes, bulk jobs, clocking, and the reconciler's local import.
 * The *_WRITES_ENABLED flags answer "may this service write at all"; this
 * answers "to which stores" while a rollout is in progress.
 */
class ExternalWriteGuard
{
    /** @return array<int, string> Empty = unrestricted. */
    public function allowedStores(): array
    {
        $raw = (string) (config('external.allowed_stores') ?? '');

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public function isRestricted(): bool
    {
        return $this->allowedStores() !== [];
    }

    public function allows(string $storeNumber): bool
    {
        $allowed = $this->allowedStores();

        return $allowed === [] || in_array($storeNumber, $allowed, true);
    }

    /** @throws StoreNotAllowlistedException */
    public function assertAllowed(string $storeNumber): void
    {
        if (!$this->allows($storeNumber)) {
            throw new StoreNotAllowlistedException($storeNumber);
        }
    }
}
