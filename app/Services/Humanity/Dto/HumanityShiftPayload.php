<?php

namespace App\Services\Humanity\Dto;

use Carbon\CarbonImmutable;

/**
 * Everything Humanity needs to create or update a shift, in OUR terms.
 * HumanityShiftMapper turns this into the wire format.
 *
 * `startsLocal`/`endsLocal` are wall-clock in the store's timezone, because
 * that is what Humanity stores — it has no per-request timezone parameter.
 */
final class HumanityShiftPayload
{
    /**
     * @param  array<int, string>  $employeeIds  Humanity employee ids
     */
    public function __construct(
        public readonly string $locationId,
        public readonly string $positionId,
        public readonly CarbonImmutable $startsLocal,
        public readonly CarbonImmutable $endsLocal,
        public readonly array $employeeIds = [],
        public readonly ?string $title = null,
        public readonly ?string $note = null,
        public readonly int $slots = 1,
        public readonly bool $open = false,
        public readonly bool $published = false,
        public readonly ?string $idempotencyKey = null,
    ) {
    }

    public function withEmployees(array $employeeIds): self
    {
        return new self(
            $this->locationId,
            $this->positionId,
            $this->startsLocal,
            $this->endsLocal,
            $employeeIds,
            $this->title,
            $this->note,
            $this->slots,
            $this->open,
            $this->published,
            $this->idempotencyKey,
        );
    }
}
