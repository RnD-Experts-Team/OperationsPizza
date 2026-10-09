<?php

namespace App\Services\Scheduling;

use App\Models\Employee;
use App\Models\StoreScheduleSetting;
use Illuminate\Support\Collection;

/**
 * Labor cost from the employee's current wage.
 *
 * We keep one rate per employee rather than HiringPizza's pay history — pay
 * history is HR data, and this service only ever needs "what does an hour of
 * this person cost". The rate is refreshed whenever an employee event lands.
 *
 * Consequence, and it is deliberate: costing is no longer date-effective. A
 * raise re-costs previously scheduled weeks at the new rate, where the old
 * pay-history lookup would have kept them at the rate in force on the day.
 * `$onDate` is therefore accepted but unused — kept so callers (and the API
 * shape) do not have to change if history ever comes back.
 */
class LaborCostCalculator
{
    /** @var array<int, float|null> */
    private array $rateCache = [];

    public function rateFor(int $employeeId, string $onDate, ?StoreScheduleSetting $settings = null): float
    {
        if (!array_key_exists($employeeId, $this->rateCache)) {
            $this->rateCache[$employeeId] = $this->lookup([$employeeId])[$employeeId] ?? null;
        }

        $rate = $this->rateCache[$employeeId];

        if ($rate !== null) {
            return $rate;
        }

        // No wage replicated yet (e.g. a brand-new hire) — fall back to the
        // store default rather than reporting a zero-cost schedule.
        $fallback = $settings?->default_labor_rate_cents;

        return $fallback === null ? 0.0 : $fallback / 100;
    }

    /** @param  Collection<int, array{employee_id:int, shift_date:string, duration_minutes:int}>  $shifts */
    public function totalFor(Collection $shifts, ?StoreScheduleSetting $settings = null): float
    {
        return round($shifts->reduce(function (float $carry, array $shift) use ($settings) {
            $rate = $this->rateFor((int) $shift['employee_id'], $shift['shift_date'], $settings);

            return $carry + ($rate * ($shift['duration_minutes'] / 60));
        }, 0.0), 2);
    }

    /** Warm the cache for a whole roster in one query. */
    public function preload(array $employeeIds): void
    {
        $rates = $this->lookup($employeeIds);

        foreach ($employeeIds as $employeeId) {
            $this->rateCache[(int) $employeeId] = $rates[(int) $employeeId] ?? null;
        }
    }

    /** @return array<int, float|null> */
    private function lookup(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        return Employee::query()
            ->whereIn('id', $employeeIds)
            ->pluck('hourly_rate', 'id')
            ->map(fn ($rate) => $rate === null ? null : (float) $rate)
            ->all();
    }
}
