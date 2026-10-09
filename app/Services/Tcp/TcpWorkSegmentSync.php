<?php

namespace App\Services\Tcp;

use App\Models\Employee;
use App\Models\Store;
use App\Models\TcpWorkSegment as WorkSegmentRow;
use App\Services\Scheduling\ActualShiftRollup;
use App\Services\Scheduling\StoreTimezoneResolver;
use App\Services\Tcp\Dto\TcpWorkSegment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Pulls worked segments from TCP into `tcp_work_segments`, then rolls them up
 * into the shifts a manager reviews.
 *
 * The pull is EMPLOYEE-scoped, not origin-scoped: TCP is asked for an
 * employee's segments and returns them whether the punch came from our API, a
 * physical clock, or TCP's own web UI. That is what makes "we get the sync of it
 * even if he didn't clock in using our system" true.
 *
 * Two things changed here that matter:
 *
 * 1. OPEN segments are now persisted rather than discarded. They are the whole
 *    of "who is on the clock right now" — a fact that previously lived in a
 *    separate table only our own API ever wrote to, so a punch made anywhere
 *    else never reached it.
 *
 * 2. A segment is no longer an actual shift. Punching out and back in closes one
 *    segment and opens another, so a shift routinely arrives as several;
 *    ActualShiftRollup decides which belong together.
 *
 * Uses TCP's `updatedOnStart` delta filter, which Humanity has no equivalent of.
 * That matters here more than anywhere else: TCP allows 2500 calls per day
 * account-wide, so re-reading a fortnight of segments every run would spend the
 * budget before lunch.
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
        private readonly ActualShiftRollup $rollup,
    ) {
    }

    /**
     * `seen_segment_ids` and `queried_employee_ids` are what make a sweep
     * possible: a deletion in TCP is invisible to a delta, and the only way to
     * find one is to compare what came back against what we hold. They are
     * returned rather than inferred from a timestamp because several writes in
     * the same second are indistinguishable by one.
     *
     * @return array{imported:int, updated:int, skipped:int, unlinked:int, segments:int, rollups:int, seen_segment_ids:array<int,string>, queried_employee_ids:array<int,int>}
     */
    public function sync(Store $store, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null, bool $full = false, bool $dryRun = false): array
    {
        $timezone = $this->timezones->for($store);

        // The delta cursor is what keeps this affordable. Overlap covers late
        // edits and approvals that change a segment without moving its date.
        $updatedSince = $full ? null : $this->cursor($store);

        /*
         | The date floor depends on whether this is a delta.
         |
         | `startDate` bounds the query INDEPENDENTLY of `updatedOnStart`, so a
         | 14-day floor was silently excluding a timecard dated three weeks ago
         | and corrected this morning — the delta matched it, the date window
         | threw it away. A delta run therefore looks much further back; it costs
         | nothing, because the delta still bounds the result set to what changed.
         */
        $lookback = (int) config(
            $updatedSince !== null ? 'tcp.timeclock.delta_lookback_days' : 'tcp.timeclock.lookback_days',
            $updatedSince !== null ? 90 : 14
        );

        $from ??= CarbonImmutable::now($timezone)->subDays($lookback)->startOfDay();
        $to ??= CarbonImmutable::now($timezone)->addDay()->endOfDay();

        $employees = Employee::query()
            ->whereNotNull('tcp_employee_id')
            ->assignedToStore((string) $store->store_number)
            ->get();

        $stats = [
            'imported' => 0, 'updated' => 0, 'skipped' => 0, 'unlinked' => 0,
            'segments' => 0, 'rollups' => 0,
            'seen_segment_ids' => [], 'queried_employee_ids' => [],
        ];

        if ($employees->isEmpty()) {
            Log::info('TCP work segment sync skipped: no employees linked to TCP', [
                'store_number' => $store->store_number,
            ]);

            return $stats;
        }

        // ONE call answers "did anything happen?" for the whole account. When
        // nothing has — the overwhelmingly common case on a ten-minute schedule
        // — the round ends here having spent a single request instead of paging
        // every employee's segments.
        //
        // TCP does not document whether this feed reflects raw punch inserts or
        // only its own recalculations, so treating it as a gate carries a real
        // risk of skipping a punch. `tcp:reconcile-worksegments` re-reads the
        // window nightly regardless, which bounds that risk to a day.
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

        // Budget check before starting: employeeIds chunk at 20 (TCP's
        // documented maximum), so this is roughly ceil(n/20) calls plus paging.
        // Better to skip a run than to stop halfway and leave a partial picture.
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
        // Whose segments this pass actually asked TCP about. A sweep may only
        // retire rows for these people: anyone skipped here simply was not
        // looked up, which is a different thing from TCP not having them.
        $stats['queried_employee_ids'] = $employees->pluck('id')->map(fn ($id) => (int) $id)->all();

        /** @var array<int, array{employee: Employee, anchors: array<string, CarbonImmutable>}> $touched */
        $touched = [];

        foreach ($segments as $segment) {
            $employee = $byTcpId->get($segment->employeeId);

            if ($employee === null) {
                // A segment for somebody we have no link for. Skipping is right:
                // inventing an employee here would fight the hiring replication.
                //
                // Rare by construction, since employeeIds is an explicit filter
                // and TCP only returns who we asked about. The people genuinely
                // missing are the ones we never asked about at all — which is
                // what the nightly reconcile's unlinked report is for.
                $stats['unlinked']++;

                continue;
            }

            $outcome = $this->upsert($store, $employee, $segment, $timezone, WorkSegmentRow::ORIGIN_DISCOVERED, $dryRun);
            $stats[$outcome]++;
            $stats['seen_segment_ids'][] = $segment->id;

            if ($outcome !== 'skipped' && !$dryRun) {
                $anchor = CarbonImmutable::parse($segment->timeIn, $timezone)->utc();

                $touched[$employee->id]['employee'] = $employee;
                $touched[$employee->id]['anchors'][$anchor->toDateString()] = $anchor;
            }
        }

        // Grouping happens after every segment has landed, so a shift is rolled
        // up from its complete set rather than re-derived once per punch.
        foreach ($touched as $entry) {
            foreach ($entry['anchors'] as $anchor) {
                $stats['rollups'] += $this->rollup->rebuild($store, $entry['employee'], $anchor)['rollups'];
            }
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
     * just returned, so this costs no extra TCP call.
     *
     * Unlike the old behaviour, a clock-in is NOT a no-op here: the open segment
     * it produces is exactly what makes the person show as on the clock.
     *
     * @return 'imported'|'updated'|'skipped'
     */
    public function syncOne(
        Store $store,
        Employee $employee,
        TcpWorkSegment $segment,
        string $origin = WorkSegmentRow::ORIGIN_PUNCH,
        bool $rebuild = true,
    ): string {
        $timezone = $this->timezones->for($store);

        $outcome = $this->upsert($store, $employee, $segment, $timezone, $origin);

        // A caller writing several segments for one shift passes false and
        // rebuilds once at the end — otherwise the grouping is re-derived
        // between writes, from a set that is still incomplete.
        if ($rebuild && $outcome !== 'skipped' && $segment->timeIn !== null) {
            $this->rollup->rebuild($store, $employee, CarbonImmutable::parse($segment->timeIn, $timezone)->utc());
        }

        return $outcome;
    }

    /**
     * Mirror one TCP segment into its local row.
     *
     * Everything written here comes from TCP and is overwritten from TCP on
     * every pass. The two exceptions are `origin`, which TCP cannot tell us
     * afterwards because it stores a segment identically however it was made,
     * and `actual_shift_id`, which is the grouping and belongs to the roll-up.
     *
     * @return 'imported'|'updated'|'skipped'
     */
    private function upsert(Store $store, Employee $employee, TcpWorkSegment $segment, string $timezone, string $origin, bool $dryRun = false): string
    {
        if ($segment->id === '' || $segment->timeIn === null) {
            return 'skipped';
        }

        $startsLocal = CarbonImmutable::parse($segment->timeIn, $timezone);
        $startsUtc = $startsLocal->utc();

        // An OPEN segment is somebody still on the clock. It is persisted, not
        // discarded: there is no end time to record yet, and that is the point.
        $open = $segment->isOpen();

        $endsLocal = $open ? null : CarbonImmutable::parse($segment->timeOut, $timezone);
        $endsUtc = $endsLocal?->utc();

        if ($endsLocal !== null && $endsLocal <= $startsLocal) {
            return 'skipped';
        }

        // True elapsed time, so it stays correct across both DST transitions —
        // the same rule the planned side uses.
        $durationMinutes = $endsUtc === null
            ? null
            : (int) round(($endsUtc->getTimestamp() - $startsUtc->getTimestamp()) / 60);

        $existing = WorkSegmentRow::query()
            ->withTrashed()
            ->where('tcp_work_segment_id', $segment->id)
            ->first();

        $attributes = [
            'store_id' => $store->id,
            'employee_id' => $employee->id,
            'tcp_employee_id' => (string) $employee->tcp_employee_id,
            'tcp_job_code_id' => $segment->jobCodeId,
            'time_in' => $startsLocal->toDateTimeString(),
            'time_out' => $endsLocal?->toDateTimeString(),
            'starts_at_utc' => $startsUtc->toDateTimeString(),
            'ends_at_utc' => $endsUtc?->toDateTimeString(),
            'duration_minutes' => $durationMinutes,
            'actual_punch_in_at' => $segment->actualTimeIn === null
                ? null
                : CarbonImmutable::parse($segment->actualTimeIn, $timezone)->utc()->toDateTimeString(),
            'actual_punch_out_at' => $segment->actualTimeOut === null
                ? null
                : CarbonImmutable::parse($segment->actualTimeOut, $timezone)->utc()->toDateTimeString(),
            'missed_in_punch' => $segment->missedInPunch,
            'missed_out_punch' => $segment->missedOutPunch,
            // TCP's own notes, kept strictly apart from the manager's note on
            // the roll-up. One column serving both is how a sync carrying no
            // shift note silently erased what a manager had typed.
            'segment_note' => $segment->note(),
            'tcp_updated_on' => $segment->updatedOn,
            'last_seen_at' => CarbonImmutable::now()->toDateTimeString(),
        ];

        if ($dryRun) {
            return $existing !== null ? 'updated' : 'imported';
        }

        if ($existing !== null) {
            // A segment that came back from TCP exists again, whatever a
            // previous reconcile concluded.
            if ($existing->trashed()) {
                $existing->restore();
            }

            $existing->update($attributes);

            return 'updated';
        }

        WorkSegmentRow::query()->create($attributes + [
            'tcp_work_segment_id' => $segment->id,
            'origin' => $origin,
        ]);

        return 'imported';
    }

    /**
     * The changed-employee list, memoised for this process.
     *
     * The feed is ACCOUNT-WIDE, not per store, so syncing ten stores must not
     * cost ten calls. Caching it briefly means one call serves the whole run and
     * any other job that happens to ask in the same window.
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

        // Floor to a bucket so every store in a run shares one key — and one
        // call. Each store's cursor sits on a slightly different minute, which
        // otherwise split the account-wide feed into a call per minute spanned.
        // Rounding down widens the window, so the answer is a superset of who
        // changed; the caller intersects it with the store's own roster anyway.
        $bucketMinutes = max(1, (int) config('tcp.timeclock.changes_bucket_minutes', 5));
        $bucket = $since->subMinutes($since->minute % $bucketMinutes)->startOfMinute();

        $cacheKey = self::CHANGES_KEY . ':' . $bucket->format('YmdHi');

        $result = Cache::remember(
            $cacheKey,
            (int) config('tcp.timeclock.changes_cache_seconds', 240),
            fn () => $this->tcp->listCalculationChanges($bucket)
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
