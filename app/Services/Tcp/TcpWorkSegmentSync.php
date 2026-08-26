<?php

namespace App\Services\Tcp;

use App\Models\ActualShift;
use App\Models\Employee;
use App\Models\ShiftAssignment;
use App\Models\Store;
use App\Services\Scheduling\StoreTimezoneResolver;
use App\Services\Tcp\Dto\TcpWorkSegment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pulls worked segments from TCP into `actual_shifts`.
 *
 * This is what makes planned-vs-actual real rather than a manual review chore:
 * the planned side comes from Humanity, the actual side from the time clock,
 * and the grid compares them.
 *
 * Uses TCP's `updatedOnStart` delta filter, which Humanity has no equivalent
 * of. That matters here more than anywhere else in the system — TCP allows only
 * 2500 calls per day, so re-reading a fortnight of segments on every run would
 * consume the budget within hours.
 */
class TcpWorkSegmentSync
{
    private const CURSOR_KEY = 'tcp:worksegments:cursor';

    /** The change feed is account-wide, so it is cached across stores. */
    private const CHANGES_KEY = 'tcp:calculationchanges';

    public function __construct(
        private readonly TcpClientInterface $tcp,
        private readonly StoreTimezoneResolver $timezones,
        private readonly TcpRateLimiter $limiter,
    ) {
    }

    /**
     * @return array{imported:int, updated:int, skipped:int, unlinked:int, segments:int}
     */
    public function sync(Store $store, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null, bool $full = false, bool $dryRun = false): array
    {
        $timezone = $this->timezones->for($store);
        $lookback = (int) config('tcp.timeclock.lookback_days', 14);

        $from ??= CarbonImmutable::now($timezone)->subDays($lookback)->startOfDay();
        $to ??= CarbonImmutable::now($timezone)->addDay()->endOfDay();

        // The delta cursor is what keeps this affordable. Overlap covers late
        // edits and approvals that change a segment without moving its date.
        $updatedSince = $full ? null : $this->cursor($store);

        $employees = Employee::query()
            ->whereNotNull('tcp_employee_id')
            ->assignedToStore((string) $store->store_number)
            ->get();

        $stats = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'unlinked' => 0, 'segments' => 0];

        if ($employees->isEmpty()) {
            Log::info('TCP work segment sync skipped: no employees linked to TCP', [
                'store_number' => $store->store_number,
            ]);

            return $stats;
        }

        // ONE call answers "did anything happen?" for the whole account. When
        // nothing has — the overwhelmingly common case on an hourly schedule —
        // the round ends here having spent a single request instead of paging
        // every employee's segments. This is the difference between affording
        // an hourly sync and burning the 2500/day quota by lunchtime.
        if (!$full) {
            $changed = $this->changedEmployeeIds($updatedSince);

            if ($changed !== null) {
                $relevant = array_intersect(
                    $employees->map(fn (Employee $e) => (string) $e->tcp_employee_id)->all(),
                    $changed
                );

                if ($relevant === []) {
                    Log::info('TCP work segment sync: nothing changed', [
                        'store_number' => $store->store_number,
                        'cost' => '1 request',
                    ]);

                    $this->rememberCursor($store, CarbonImmutable::now());

                    return $stats;
                }

                // Narrow to just the affected people — usually a handful rather
                // than the whole roster, so one chunk instead of several.
                $employees = $employees->filter(
                    fn (Employee $e) => in_array((string) $e->tcp_employee_id, $relevant, true)
                )->values();
            }
        }

        // Budget check before starting: employeeIds chunk at 20, so this is
        // roughly ceil(n/20) calls plus paging. Better to skip a run than to
        // stop halfway and leave a partial picture.
        $estimated = (int) ceil($employees->count() / (int) config('tcp.max_ids_per_request', 20)) + 1;

        if (!$this->limiter->canAfford($estimated)) {
            Log::warning('TCP work segment sync skipped: daily quota too low', [
                'store_number' => $store->store_number,
                'estimated_calls' => $estimated,
                'remaining_today' => $this->limiter->remainingToday(),
            ]);

            $stats['skipped'] = $employees->count();

            return $stats;
        }

        $byTcpId = $employees->keyBy(fn (Employee $e) => (string) $e->tcp_employee_id);

        $startedAt = CarbonImmutable::now();

        // Cast back to strings: keyBy() puts a numeric id like "501" into the
        // array as int 501, and TCP ids are opaque strings everywhere else.
        $tcpIds = $employees->map(fn (Employee $e) => (string) $e->tcp_employee_id)->values()->all();

        $segments = $this->tcp->listWorkSegments($from, $to, $tcpIds, $updatedSince);

        $stats['segments'] = count($segments);

        foreach ($segments as $segment) {
            $employee = $byTcpId->get($segment->employeeId);

            if ($employee === null) {
                // A segment for somebody at this location we have no link for.
                // Skipping is right: inventing an employee here would fight the
                // hiring replication.
                $stats['unlinked']++;

                continue;
            }

            $outcome = $this->upsert($store, $employee, $segment, $timezone, $dryRun);
            $stats[$outcome]++;
        }

        // Only advance the cursor after a clean pass, and back-date it by the
        // overlap so an edit landing mid-run is not missed forever. A dry run
        // must not move it — the next real pass still needs these segments.
        if (!$dryRun) {
            $this->rememberCursor($store, $startedAt);
        }

        Log::info('TCP work segment sync finished', ['store_number' => $store->store_number] + $stats);

        return $stats;
    }

    /**
     * Persist ONE segment immediately — the segment TCP's own punch response
     * just returned, so this costs no extra TCP call. Same rule as the bulk
     * sync: an open segment (still on the clock) is correctly skipped until
     * it closes, so calling this after every punch type is safe — a
     * clock-in naturally no-ops here and a clock-out is what actually lands.
     *
     * @return 'imported'|'updated'|'skipped'
     */
    public function syncOne(Store $store, Employee $employee, TcpWorkSegment $segment): string
    {
        return $this->upsert($store, $employee, $segment, $this->timezones->for($store));
    }

    /** @return 'imported'|'updated'|'skipped' */
    private function upsert(Store $store, Employee $employee, TcpWorkSegment $segment, string $timezone, bool $dryRun = false): string
    {
        // An open segment is someone still on the clock. Recording it as an
        // actual shift would show a shift that ends now and keeps moving.
        if ($segment->isOpen()) {
            return 'skipped';
        }

        if ($segment->timeIn === null || $segment->timeOut === null) {
            return 'skipped';
        }

        $startsLocal = CarbonImmutable::parse($segment->timeIn, $timezone);
        $endsLocal = CarbonImmutable::parse($segment->timeOut, $timezone);

        if ($endsLocal <= $startsLocal) {
            return 'skipped';
        }

        $startsUtc = $startsLocal->utc();
        $endsUtc = $endsLocal->utc();

        // True elapsed time, so it stays correct across both DST transitions —
        // the same rule the planned side uses.
        $durationMinutes = (int) round(($endsUtc->getTimestamp() - $startsUtc->getTimestamp()) / 60);

        $assignment = $this->matchPlannedAssignment($employee, $startsUtc, $endsUtc);

        $existing = ActualShift::query()
            ->where('tcp_work_segment_id', $segment->id)
            ->first();

        $attributes = [
            'store_id' => $store->id,
            'employee_id' => $employee->id,
            'shift_assignment_id' => $assignment?->id,
            'shift_date' => $startsLocal->toDateString(),
            'start_time' => $startsLocal->format('H:i:s'),
            'end_time' => $endsLocal->format('H:i:s'),
            'starts_at_utc' => $startsUtc->toDateTimeString(),
            'ends_at_utc' => $endsUtc->toDateTimeString(),
            'duration_minutes' => $durationMinutes,
            'crosses_midnight' => $endsLocal->toDateString() !== $startsLocal->toDateString(),
            'status' => $this->deriveStatus($assignment, $startsLocal, $endsLocal),
            'note' => $segment->note(),
            'source' => 'timeclock',
            'tcp_work_segment_id' => $segment->id,
            'has_missed_punch' => $segment->hasMissedPunch(),
            'actual_punch_in_at' => $segment->actualTimeIn === null
                ? null
                : CarbonImmutable::parse($segment->actualTimeIn, $timezone)->utc()->toDateTimeString(),
            'actual_punch_out_at' => $segment->actualTimeOut === null
                ? null
                : CarbonImmutable::parse($segment->actualTimeOut, $timezone)->utc()->toDateTimeString(),
        ];

        if ($dryRun) {
            return $existing !== null ? 'updated' : 'imported';
        }

        return DB::transaction(function () use ($existing, $attributes) {
            if ($existing !== null) {
                $existing->update($attributes);

                return 'updated';
            }

            ActualShift::query()->create($attributes);

            return 'imported';
        });
    }

    /**
     * Find the planned assignment this segment most likely fulfils.
     *
     * Overlap rather than exact match: people clock in early and leave late,
     * which is the entire point of comparing planned to actual.
     */
    private function matchPlannedAssignment(Employee $employee, CarbonImmutable $startsUtc, CarbonImmutable $endsUtc): ?ShiftAssignment
    {
        return ShiftAssignment::query()
            ->with('shift')
            ->where('employee_id', $employee->id)
            ->whereHas('shift', fn ($query) => $query->overlapping($startsUtc, $endsUtc))
            // Nearest start wins when a split shift produces two candidates.
            ->get()
            ->sortBy(fn (ShiftAssignment $a) => abs(
                ($a->shift?->starts_at_utc?->getTimestamp() ?? 0) - $startsUtc->getTimestamp()
            ))
            ->first();
    }

    /**
     * Status is derived, never asserted — the same rule as manual review, so
     * the two paths cannot disagree.
     */
    private function deriveStatus(?ShiftAssignment $assignment, CarbonImmutable $startsLocal, CarbonImmutable $endsLocal): string
    {
        if ($assignment?->shift === null) {
            return ActualShift::STATUS_ADDED;
        }

        $shift = $assignment->shift;

        $sameTimes = substr((string) $shift->start_time, 0, 5) === $startsLocal->format('H:i')
            && substr((string) $shift->end_time, 0, 5) === $endsLocal->format('H:i');

        return $sameTimes ? ActualShift::STATUS_CONFIRMED : ActualShift::STATUS_MODIFIED;
    }

    /**
     * The changed-employee list, memoised for this process.
     *
     * The feed is ACCOUNT-WIDE, not per store, so syncing ten stores must not
     * cost ten calls. Caching it briefly means one call serves the whole run
     * and any other job that happens to ask in the same window.
     *
     * Returns null when the answer is "assume everyone" — either TCP reported a
     * bulk recalculation, or we have no cursor yet and cannot ask a meaningful
     * question.
     *
     * @return array<int, string>|null
     */
    private function changedEmployeeIds(?CarbonImmutable $since): ?array
    {
        if ($since === null) {
            return null;
        }

        $cacheKey = self::CHANGES_KEY . ':' . $since->format('YmdHi');

        $result = Cache::remember(
            $cacheKey,
            (int) config('tcp.timeclock.changes_cache_seconds', 120),
            fn () => $this->tcp->listCalculationChanges($since)
        );

        if (!is_array($result) || ($result['all_changed'] ?? false)) {
            return null;
        }

        return array_map('strval', $result['employee_ids'] ?? []);
    }

    private function cursor(Store $store): ?CarbonImmutable
    {
        $stored = Cache::get($this->cursorKey($store));

        if (!is_string($stored)) {
            return null;
        }

        $overlap = (int) config('tcp.timeclock.delta_overlap_minutes', 30);

        return CarbonImmutable::parse($stored)->subMinutes($overlap);
    }

    private function rememberCursor(Store $store, CarbonImmutable $at): void
    {
        Cache::put($this->cursorKey($store), $at->toIso8601String(), now()->addDays(30));
    }

    private function cursorKey(Store $store): string
    {
        return self::CURSOR_KEY . ':' . $store->id;
    }
}
