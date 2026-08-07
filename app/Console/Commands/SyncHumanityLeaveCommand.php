<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\HumanityLocation;
use App\Models\Store;
use App\Models\TimeOff;
use App\Services\Humanity\HumanityClientInterface;
use App\Services\Humanity\HumanityDateFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors Humanity leave ("vacations") into time_off.
 *
 * Approved leave is a scheduling guard — a manager must not be able to book
 * someone who is on holiday — so this needs to run often enough that the grid
 * is not working from stale data. There are no webhooks, so it polls.
 */
class SyncHumanityLeaveCommand extends Command
{
    protected $signature = 'humanity:sync-leave
        {--store= : store_number; omit for every mapped store}
        {--from= : YYYY-MM-DD}
        {--to= : YYYY-MM-DD}';

    protected $description = 'Sync Humanity leave/time-off into the local mirror';

    /** Humanity leave types vary by account; map what we recognise. */
    private const TYPE_MAP = [
        'vacation' => 'vacation',
        'pto' => 'pto',
        'paid time off' => 'pto',
        'sick' => 'sick',
        'sick leave' => 'sick',
        'unpaid' => 'unpaid',
    ];

    public function handle(HumanityClientInterface $humanity, HumanityDateFormatter $dates): int
    {
        $from = CarbonImmutable::parse(
            $this->option('from') ?? now()->subDays((int) config('humanity.reconcile.days_back', 7))->toDateString()
        );

        $to = CarbonImmutable::parse(
            $this->option('to') ?? now()->addDays((int) config('humanity.reconcile.days_forward', 21))->toDateString()
        );

        $stores = Store::query()
            ->whereIn('id', HumanityLocation::query()->select('store_id'))
            ->when($this->option('store'), fn ($q, $storeNumber) => $q->where('store_number', $storeNumber))
            ->get();

        if ($stores->isEmpty()) {
            $this->warn('No mapped stores. Run humanity:sync-catalog first.');

            return self::SUCCESS;
        }

        $employeesByHumanityId = Employee::query()
            ->whereNotNull('humanity_employee_id')
            ->pluck('id', 'humanity_employee_id');

        foreach ($stores as $store) {
            $locationId = HumanityLocation::query()->where('store_id', $store->id)->value('humanity_location_id');

            $rows = $humanity->listLeave($from, $to, $locationId);
            $seen = [];
            $skipped = 0;

            foreach ($rows as $row) {
                $humanityLeaveId = $this->stringOrNull($row['id'] ?? null);
                $humanityEmployeeId = $this->stringOrNull($row['employee'] ?? $row['employee_id'] ?? $row['user_id'] ?? null);

                $employeeId = $humanityEmployeeId === null
                    ? null
                    : ($employeesByHumanityId[$humanityEmployeeId] ?? null);

                // Leave for somebody we have no link to yet. Skipping is right:
                // inventing an employee here would fight the hiring replication.
                if ($humanityLeaveId === null || $employeeId === null) {
                    $skipped++;

                    continue;
                }

                $start = $dates->parse($row['start_date'] ?? $row['start'] ?? null)?->format('Y-m-d');
                $end = $dates->parse($row['end_date'] ?? $row['end'] ?? null)?->format('Y-m-d') ?? $start;

                if ($start === null) {
                    $skipped++;

                    continue;
                }

                TimeOff::query()->updateOrCreate(
                    ['humanity_leave_id' => $humanityLeaveId],
                    [
                        'store_id' => $store->id,
                        'employee_id' => $employeeId,
                        'start_date' => $start,
                        'end_date' => $end,
                        'all_day' => true,
                        'type' => $this->mapType($row),
                        'label' => $this->stringOrNull($row['name'] ?? $row['type'] ?? null),
                        'status' => $this->mapStatus($row),
                        'note' => $this->stringOrNull($row['notes'] ?? null),
                        'origin' => 'humanity',
                    ]
                );

                $seen[] = $humanityLeaveId;
            }

            // Leave withdrawn in Humanity must stop blocking the grid here.
            $removed = DB::transaction(function () use ($store, $from, $to, $seen) {
                return TimeOff::query()
                    ->where('store_id', $store->id)
                    ->where('origin', 'humanity')
                    ->overlappingDates($from->toDateString(), $to->toDateString())
                    ->when($seen !== [], fn ($q) => $q->whereNotIn('humanity_leave_id', $seen))
                    ->delete();
            });

            $this->line(sprintf(
                '%s: %d synced, %d removed, %d skipped (no employee link)',
                $store->store_number,
                count($seen),
                $removed,
                $skipped
            ));
        }

        return self::SUCCESS;
    }

    private function mapType(array $row): string
    {
        $raw = strtolower(trim((string) ($row['type'] ?? $row['name'] ?? '')));

        foreach (self::TYPE_MAP as $needle => $type) {
            if (str_contains($raw, $needle)) {
                return $type;
            }
        }

        return 'other';
    }

    private function mapStatus(array $row): string
    {
        $raw = strtolower(trim((string) ($row['status'] ?? '')));

        return match (true) {
            str_contains($raw, 'deni'), str_contains($raw, 'reject') => 'denied',
            str_contains($raw, 'request'), str_contains($raw, 'pend') => 'pending',
            default => 'approved',
        };
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
