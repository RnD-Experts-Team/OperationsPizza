<?php

namespace App\Services\Reconciliation;

use App\Jobs\PublishOutboxEventJob;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Store;
use App\Services\Humanity\Dto\HumanityShiftResult;
use App\Services\Humanity\HumanityClientInterface;
use App\Services\Humanity\HumanityPositionResolver;
use App\Services\Humanity\HumanitySyncLogger;
use App\Services\OperationsEvents\OperationsEventFactory;
use App\Services\OperationsEvents\OperationsOutboxService;
use App\Services\Scheduling\ShiftFingerprint;
use App\Services\Scheduling\StoreTimezoneResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keeps our mirror honest against Humanity.
 *
 * This is the PRIMARY sync mechanism, not a backstop: Humanity's public API has
 * no webhooks and no `updated_at` on shifts, so polling a rolling date window
 * is the only way to notice that a manager edited the schedule in Humanity's
 * own UI or mobile app.
 *
 * Humanity always wins on a conflict. The one nuance is a local row edited
 * after we took our snapshot — that gets a targeted re-fetch first, because
 * "the system silently reverted my edit" destroys trust faster than an outage.
 */
class HumanityShiftReconciler
{
    public function __construct(
        private readonly HumanityClientInterface $humanity,
        private readonly HumanityPositionResolver $positions,
        private readonly HumanitySyncLogger $syncLog,
        private readonly ShiftFingerprint $fingerprint,
        private readonly StoreTimezoneResolver $timezones,
        private readonly OperationsEventFactory $events,
        private readonly OperationsOutboxService $outbox,
    ) {
    }

    public function reconcile(Store $store, CarbonImmutable $from, CarbonImmutable $to, bool $dryRun = false): ReconciliationReport
    {
        $report = new ReconciliationReport();

        $lock = Cache::lock("humanity:recon:{$store->id}", (int) config('humanity.reconcile.lock_seconds', 300));

        // Two passes over one store would fight each other, so a held lock
        // means "already running" — skip rather than queue.
        if (!$lock->get()) {
            $report->errors[] = 'Another reconciliation is already running for this store.';

            return $report;
        }

        try {
            return $this->run($store, $from, $to, $dryRun, $report);
        } finally {
            $lock->release();
        }
    }

    private function run(Store $store, CarbonImmutable $from, CarbonImmutable $to, bool $dryRun, ReconciliationReport $report): ReconciliationReport
    {
        $timezone = $this->timezones->for($store);
        $locationId = $this->positions->locationId($store);

        $snapshotAt = CarbonImmutable::now();

        $remote = [];

        foreach ($this->humanity->listShifts($locationId, $from, $to) as $shift) {
            if ($shift->shiftId !== '') {
                $remote[$shift->shiftId] = $shift;
            }
        }

        $report->remoteSeen = count($remote);

        $local = Shift::query()
            ->withTrashed()
            ->forStore((int) $store->id)
            ->inDateRange($from->toDateString(), $to->toDateString())
            ->with('assignments')
            ->get()
            ->keyBy('humanity_shift_id');

        foreach ($remote as $humanityShiftId => $remoteShift) {
            $localShift = $local->get($humanityShiftId);

            if ($localShift === null) {
                // Either someone created it in Humanity directly, or our own
                // write succeeded but crashed before persisting the mirror.
                $this->import($store, $remoteShift, $timezone, $dryRun, $report);

                continue;
            }

            $this->compare($store, $localShift, $remoteShift, $timezone, $snapshotAt, $dryRun, $report);
        }

        foreach ($local as $humanityShiftId => $localShift) {
            if ($humanityShiftId === null || isset($remote[$humanityShiftId]) || $localShift->trashed()) {
                continue;
            }

            $this->handleMissingUpstream($store, $localShift, $snapshotAt, $dryRun, $report);
        }

        Log::info('Humanity reconciliation finished', [
            'store_number' => $store->store_number,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'dry_run' => $dryRun,
        ] + $report->toArray());

        return $report;
    }

    private function import(Store $store, HumanityShiftResult $remote, string $timezone, bool $dryRun, ReconciliationReport $report): void
    {
        if ($remote->startDate === null || $remote->startTime === null) {
            $report->skipped++;

            return;
        }

        $report->imported++;

        $report->changes[] = [
            'action' => 'imported',
            'humanity_shift_id' => $remote->shiftId,
            'date' => $remote->startDate,
            'start' => $remote->startTime,
            'end' => $remote->endTime,
            'employees' => $remote->employeeIds,
        ];

        if ($dryRun) {
            return;
        }

        DB::transaction(function () use ($store, $remote, $timezone) {
            $shift = Shift::query()->create($this->attributesFrom($remote, $timezone) + [
                'store_id' => $store->id,
                'humanity_location_id' => $remote->locationId,
                'humanity_position_id' => $remote->positionId,
                'humanity_shift_id' => $remote->shiftId,
                'origin' => Shift::ORIGIN_HUMANITY,
                'last_reconciled_at' => now(),
            ]);

            $this->syncAssignments($shift, $remote);

            $shift->update([
                'humanity_hash' => $this->fingerprint->forRemoteShift($remote, $timezone),
            ]);

            $this->syncLog->recordReconciliation('shift', (int) $shift->id, $remote->shiftId, 'imported', [], (int) $store->id);
        });
    }

    private function compare(
        Store $store,
        Shift $localShift,
        HumanityShiftResult $remote,
        string $timezone,
        CarbonImmutable $snapshotAt,
        bool $dryRun,
        ReconciliationReport $report,
    ): void {
        $remoteHash = $this->fingerprint->forRemoteShift($remote, $timezone);

        if ($localShift->humanity_hash === $remoteHash) {
            $report->unchanged++;

            if (!$dryRun) {
                // No log row: keeps humanity_sync_log signal-only.
                $localShift->forceFill(['last_reconciled_at' => now()])->saveQuietly();
            }

            return;
        }

        // The row changed locally after we took our snapshot, so our list data
        // may simply be stale. One targeted GET settles it and closes the
        // reconciler-vs-user-edit race for the handful of contended rows.
        if ($localShift->updated_at !== null && $localShift->updated_at->gt($snapshotAt)) {
            $fresh = $this->humanity->getShift((string) $localShift->humanity_shift_id);

            if ($fresh !== null) {
                $remote = $fresh;
                $remoteHash = $this->fingerprint->forRemoteShift($remote, $timezone);

                if ($localShift->humanity_hash === $remoteHash) {
                    $report->unchanged++;

                    return;
                }
            }
        }

        $diff = $this->diff($localShift, $remote, $timezone);

        $report->updated++;

        $report->changes[] = [
            'action' => 'updated',
            'shift_id' => (int) $localShift->id,
            'humanity_shift_id' => $remote->shiftId,
            'date' => (string) $localShift->shift_date,
            'diff' => $diff,
        ];

        if ($dryRun) {
            return;
        }

        DB::transaction(function () use ($store, $localShift, $remote, $timezone, $remoteHash, $diff) {
            $localShift->update($this->attributesFrom($remote, $timezone) + [
                'humanity_position_id' => $remote->positionId ?? $localShift->humanity_position_id,
                'humanity_hash' => $remoteHash,
                'origin' => Shift::ORIGIN_RECONCILER,
                'last_reconciled_at' => now(),
            ]);

            $this->syncAssignments($localShift, $remote);

            $this->syncLog->recordReconciliation('shift', (int) $localShift->id, $remote->shiftId, 'updated', $diff, (int) $store->id);

            $this->recordEvent('operations.v1.shift.reconciled', [
                'shift_id' => $localShift->id,
                'humanity_shift_id' => $remote->shiftId,
                'store_number' => $store->store_number,
                'changed_fields' => $diff,
            ]);
        });
    }

    private function handleMissingUpstream(
        Store $store,
        Shift $localShift,
        CarbonImmutable $snapshotAt,
        bool $dryRun,
        ReconciliationReport $report,
    ): void {
        $graceSeconds = (int) config('humanity.reconcile.missing_grace_seconds', 120);

        // A shift created seconds ago may simply post-date our snapshot.
        if ($localShift->created_at !== null && $localShift->created_at->gt(now()->subSeconds($graceSeconds))) {
            $report->skipped++;

            return;
        }

        // An in-flight write whose outcome we don't know yet: deleting here
        // would race a create that may well have landed.
        if ($this->syncLog->pendingFor('shift', (int) $localShift->id) !== null) {
            $report->skipped++;

            return;
        }

        $report->deleted++;

        $report->changes[] = [
            'action' => 'deleted',
            'shift_id' => (int) $localShift->id,
            'humanity_shift_id' => $localShift->humanity_shift_id,
            'date' => (string) $localShift->shift_date,
        ];

        if ($dryRun) {
            return;
        }

        DB::transaction(function () use ($store, $localShift) {
            $localShift->assignments()->delete();
            $localShift->delete();

            $this->syncLog->recordReconciliation(
                'shift',
                (int) $localShift->id,
                $localShift->humanity_shift_id,
                'deleted_missing_upstream',
                [],
                (int) $store->id
            );

            $this->recordEvent('operations.v1.shift.deleted', [
                'shift_id' => $localShift->id,
                'humanity_shift_id' => $localShift->humanity_shift_id,
                'store_number' => $store->store_number,
                'reason' => 'missing_upstream',
            ]);
        });
    }

    /** Assignments follow Humanity exactly; unknown employees are skipped, not invented. */
    private function syncAssignments(Shift $shift, HumanityShiftResult $remote): void
    {
        $byHumanityId = Employee::query()
            ->whereIn('humanity_employee_id', $remote->employeeIds ?: ['__none__'])
            ->pluck('id', 'humanity_employee_id');

        $keep = [];

        foreach ($remote->employeeIds as $humanityEmployeeId) {
            $employeeId = $byHumanityId[$humanityEmployeeId] ?? null;

            if ($employeeId === null) {
                Log::warning('Humanity shift references an employee we have no link for', [
                    'humanity_shift_id' => $remote->shiftId,
                    'humanity_employee_id' => $humanityEmployeeId,
                ]);

                continue;
            }

            $shift->assignments()->withTrashed()->updateOrCreate(
                ['shift_id' => $shift->id, 'employee_id' => $employeeId],
                ['humanity_employee_id' => $humanityEmployeeId, 'status' => 'assigned', 'deleted_at' => null]
            );

            $keep[] = $employeeId;
        }

        $shift->assignments()
            ->when($keep !== [], fn ($query) => $query->whereNotIn('employee_id', $keep))
            ->delete();
    }

    private function attributesFrom(HumanityShiftResult $remote, string $timezone): array
    {
        $startsLocal = CarbonImmutable::parse("{$remote->startDate} {$remote->startTime}", $timezone);

        $endDate = $remote->endDate ?? $remote->startDate;
        $endTime = $remote->endTime ?? $remote->startTime;
        $endsLocal = CarbonImmutable::parse("{$endDate} {$endTime}", $timezone);

        if ($endsLocal <= $startsLocal) {
            $endsLocal = $endsLocal->addDay();
        }

        $startsAtUtc = $startsLocal->utc();
        $endsAtUtc = $endsLocal->utc();

        return [
            'shift_date' => $startsLocal->toDateString(),
            'start_time' => $startsLocal->format('H:i:s'),
            'end_time' => $endsLocal->format('H:i:s'),
            'starts_at_utc' => $startsAtUtc->toDateTimeString(),
            'ends_at_utc' => $endsAtUtc->toDateTimeString(),
            'duration_minutes' => (int) round(($endsAtUtc->getTimestamp() - $startsAtUtc->getTimestamp()) / 60),
            'crosses_midnight' => $endsLocal->toDateString() !== $startsLocal->toDateString(),
            'label' => $remote->title,
            'note' => $remote->note,
            'slots' => $remote->slots,
            'is_published' => $remote->published,
        ];
    }

    private function diff(Shift $local, HumanityShiftResult $remote, string $timezone): array
    {
        $remoteAttributes = $this->attributesFrom($remote, $timezone);
        $diff = [];

        foreach (['shift_date', 'start_time', 'end_time', 'note', 'slots', 'is_published'] as $field) {
            $localValue = $local->{$field};

            if ($field === 'shift_date') {
                $localValue = $local->shift_date?->toDateString();
            }

            if ((string) $localValue !== (string) $remoteAttributes[$field]) {
                $diff[$field] = ['local' => $localValue, 'remote' => $remoteAttributes[$field]];
            }
        }

        $localEmployees = $local->assignments->pluck('humanity_employee_id')->filter()->sort()->values()->all();
        $remoteEmployees = collect($remote->employeeIds)->sort()->values()->all();

        if ($localEmployees !== $remoteEmployees) {
            $diff['employees'] = ['local' => $localEmployees, 'remote' => $remoteEmployees];
        }

        return $diff;
    }

    private function recordEvent(string $subject, array $data): void
    {
        $envelope = $this->events->make($subject, $data);
        $row = $this->outbox->record($subject, $envelope);

        PublishOutboxEventJob::dispatch($row->id);
    }
}
