<?php

namespace App\Services\Scheduling;

use App\Models\EmployeePayHistory;
use App\Models\StoreScheduleSetting;
use Illuminate\Support\Collection;

/**
 * Real labor cost from the replicated pay history, replacing the hardcoded $15
 * the prototype grid uses.
 *
 * The rate is whichever pay row was effective ON the shift date — not the
 * latest one — so a raise doesn't retroactively rewrite last month's cost.
 */
class LaborCostCalculator
{
    /** @var array<int, array<int, array{date:string, rate:float}>> */
    private array $historyCache = [];

    public function rateFor(int $employeeId, string $onDate, ?StoreScheduleSetting $settings = null): float
    {
        $history = $this->historyFor($employeeId);

        foreach ($history as $row) {
            if ($row['date'] <= $onDate) {
                return $row['rate'];
            }
        }

        // No pay row effective yet (e.g. a brand-new hire) — fall back to the
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

    /** Newest-first, so the first row whose date has passed is the effective one. */
    private function historyFor(int $employeeId): array
    {
        if (!isset($this->historyCache[$employeeId])) {
            $this->historyCache[$employeeId] = EmployeePayHistory::query()
                ->where('employee_id', $employeeId)
                ->orderByDesc('effective_date')
                ->orderByDesc('id')
                ->get()
                ->map(fn (EmployeePayHistory $row) => [
                    'date' => $row->effective_date?->toDateString() ?? '0000-00-00',
                    'rate' => (float) $row->base_pay,
                ])
                ->all();
        }

        return $this->historyCache[$employeeId];
    }

    /** Warm the cache for a whole roster in one query. */
    public function preload(array $employeeIds): void
    {
        $rows = EmployeePayHistory::query()
            ->whereIn('employee_id', $employeeIds)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->get()
            ->groupBy('employee_id');

        foreach ($employeeIds as $employeeId) {
            $this->historyCache[$employeeId] = ($rows[$employeeId] ?? collect())
                ->map(fn (EmployeePayHistory $row) => [
                    'date' => $row->effective_date?->toDateString() ?? '0000-00-00',
                    'rate' => (float) $row->base_pay,
                ])
                ->all();
        }
    }
}
