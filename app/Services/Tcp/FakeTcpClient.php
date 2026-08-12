<?php

namespace App\Services\Tcp;

use App\Services\Tcp\Dto\TcpPunch;
use App\Services\Tcp\Dto\TcpWorkSegment;
use App\Services\Tcp\Exceptions\TcpException;
use App\Services\Tcp\Exceptions\TcpRateLimitException;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * In-memory TCP double. Default driver, so the clocking flow can be built and
 * verified before anyone issues API credentials.
 *
 * Models the behaviours that actually bite: an open segment must be closed by a
 * clockOut rather than a second clockIn, punches mutate segments rather than
 * creating independent rows, and the daily quota is small enough to hit.
 */
class FakeTcpClient implements TcpClientInterface
{
    /** @var array<string, TcpWorkSegment> */
    public array $segments = [];

    /** @var array<string, array<string, mixed>> */
    public array $employees = [];

    /** @var array<int, array<string, mixed>> */
    public array $jobCodes = [];

    /** @var array<int, array<string, mixed>> */
    public array $timeOffRequests = [];

    /** @var array<int, array{op:string, args:array}> */
    public array $calls = [];

    public bool $pingResult = true;

    private int $nextSegmentId = 5000;

    private array $failures = [];

    // ------------------------------------------------------------ test rigging

    public function failNext(string $operation, ?\Throwable $exception = null): void
    {
        $this->failures[$operation] = $exception ?? new TcpException("Simulated TCP failure on {$operation}");
    }

    public function rateLimitNext(string $operation): void
    {
        $this->failNext($operation, new TcpRateLimitException('Simulated TCP rate limit', 60, false, 429));
    }

    public function seedEmployee(string $employeeId, array $attributes = []): void
    {
        $this->employees[$employeeId] = array_merge([
            'employeeId' => $employeeId,
            'firstName' => 'Test',
            'lastName' => "Employee {$employeeId}",
            'exportCode' => null,
        ], $attributes);
    }

    public function seedJobCode(string $id, string $name): void
    {
        $this->jobCodes[] = ['jobCodeId' => $id, 'name' => $name];
    }

    public function seedSegment(TcpWorkSegment $segment): void
    {
        $this->segments[$segment->id] = $segment;
    }

    /** The open segment for an employee, if any. */
    public function openSegmentFor(string $employeeId): ?TcpWorkSegment
    {
        foreach ($this->segments as $segment) {
            if ($segment->employeeId === $employeeId && $segment->isOpen()) {
                return $segment;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- interface

    public function ping(): bool
    {
        $this->guard('ping');

        return $this->pingResult;
    }

    public function punch(array $punches): array
    {
        $this->guard('punch');
        $this->assertWritesEnabled('punch');

        $result = [];

        foreach ($punches as $punch) {
            $result[] = $this->applyPunch($punch);
        }

        $this->calls[] = ['op' => 'punch', 'args' => ['count' => count($punches)]];

        return $result;
    }

    private function applyPunch(TcpPunch $punch): TcpWorkSegment
    {
        $open = $this->openSegmentFor($punch->employeeId);

        return match ($punch->operationType) {
            TcpPunch::CLOCK_IN, TcpPunch::BREAK_END => $this->openSegment($punch, $open),
            TcpPunch::CLOCK_OUT, TcpPunch::BREAK_START => $this->closeSegment($punch, $open),
            default => $this->closeSegment($punch, $open),
        };
    }

    private function openSegment(TcpPunch $punch, ?TcpWorkSegment $open): TcpWorkSegment
    {
        // TCP rejects a second clock-in while a segment is open; without this
        // the fake would let a bug through that production would not.
        if ($open !== null) {
            throw new TcpException(
                "TCP punch failed: employee {$punch->employeeId} is already clocked in (segment {$open->id})."
            );
        }

        $id = (string) $this->nextSegmentId++;

        $segment = new TcpWorkSegment(
            id: $id,
            employeeId: $punch->employeeId,
            jobCodeId: $punch->jobCodeId,
            timeIn: $punch->timeIn?->format('Y-m-d\TH:i:s'),
            timeOut: null,
            actualTimeIn: $punch->timeIn?->format('Y-m-d\TH:i:s'),
            updatedOn: CarbonImmutable::now()->format('Y-m-d\TH:i:s'),
        );

        return $this->segments[$id] = $segment;
    }

    private function closeSegment(TcpPunch $punch, ?TcpWorkSegment $open): TcpWorkSegment
    {
        if ($open === null) {
            throw new TcpException(
                "TCP punch failed: employee {$punch->employeeId} is not clocked in."
            );
        }

        $closed = new TcpWorkSegment(
            id: $open->id,
            employeeId: $open->employeeId,
            jobCodeId: $open->jobCodeId,
            timeIn: $open->timeIn,
            timeOut: $punch->timeOut?->format('Y-m-d\TH:i:s'),
            actualTimeIn: $open->actualTimeIn,
            actualTimeOut: $punch->timeOut?->format('Y-m-d\TH:i:s'),
            missedInPunch: $open->missedInPunch,
            missedOutPunch: $open->missedOutPunch,
            breakLength: $open->breakLength,
            shiftNotes: $open->shiftNotes,
            updatedOn: CarbonImmutable::now()->format('Y-m-d\TH:i:s'),
        );

        return $this->segments[$open->id] = $closed;
    }

    public function listWorkSegments(
        DateTimeInterface $from,
        DateTimeInterface $to,
        array $employeeIds = [],
        ?DateTimeInterface $updatedSince = null,
    ): array {
        $this->guard('listWorkSegments');

        // Recorded so tests can assert the QUOTA COST, not just the result.
        // Against a 2500/day limit the number of calls is part of correctness.
        $this->calls[] = ['op' => 'listWorkSegments', 'args' => ['employees' => count($employeeIds)]];

        $fromStr = CarbonImmutable::instance($from)->format('Y-m-d\TH:i:s');
        $toStr = CarbonImmutable::instance($to)->format('Y-m-d\TH:i:s');
        $since = $updatedSince === null ? null : CarbonImmutable::instance($updatedSince)->format('Y-m-d\TH:i:s');

        // Compare as strings. A real HTTP API sees a CSV and doesn't care about
        // PHP types, so a strict comparison here would make the double reject
        // input that production accepts — the fake must not be stricter.
        $wanted = array_map('strval', $employeeIds);

        return array_values(array_filter($this->segments, function (TcpWorkSegment $segment) use ($fromStr, $toStr, $wanted, $since) {
            if ($wanted !== [] && !in_array((string) $segment->employeeId, $wanted, true)) {
                return false;
            }

            if ($segment->timeIn === null || $segment->timeIn < $fromStr || $segment->timeIn > $toStr) {
                return false;
            }

            // The delta filter, modelled so tests can prove we actually use it.
            if ($since !== null && ($segment->updatedOn === null || $segment->updatedOn < $since)) {
                return false;
            }

            return true;
        }));
    }

    public function getWorkSegment(string $id): ?TcpWorkSegment
    {
        $this->guard('getWorkSegment');

        return $this->segments[$id] ?? null;
    }

    /** @var array<int, string>|null  Set to simulate TCP reporting changes. */
    public ?array $calculationChanges = null;

    public bool $allEmployeesChanged = false;

    public function listCalculationChanges(?DateTimeInterface $since = null): array
    {
        $this->guard('listCalculationChanges');

        $this->calls[] = ['op' => 'listCalculationChanges', 'args' => []];

        // Default behaviour derives changes from the seeded segments, so tests
        // don't have to set the feed by hand for the common case.
        if ($this->calculationChanges === null) {
            $sinceStr = $since === null
                ? null
                : CarbonImmutable::instance($since)->format('Y-m-d\TH:i:s');

            $changed = [];

            foreach ($this->segments as $segment) {
                if ($sinceStr === null || ($segment->updatedOn !== null && $segment->updatedOn >= $sinceStr)) {
                    $changed[] = $segment->employeeId;
                }
            }

            return [
                'all_changed' => $this->allEmployeesChanged,
                'employee_ids' => array_values(array_unique($changed)),
            ];
        }

        return [
            'all_changed' => $this->allEmployeesChanged,
            'employee_ids' => $this->calculationChanges,
        ];
    }

    public function listEmployees(array $filters = []): array
    {
        $this->guard('listEmployees');

        return array_values($this->employees);
    }

    public function getEmployee(string $employeeId): ?array
    {
        $this->guard('getEmployee');

        return $this->employees[$employeeId] ?? null;
    }

    public function createEmployee(array $payload): array
    {
        $this->guard('createEmployee');
        $this->assertWritesEnabled('create employee');

        // employeeId is client-supplied in TCP — that is what makes the push
        // idempotent, so the fake must honour it rather than inventing one.
        $employeeId = (string) ($payload['employeeId'] ?? '');

        if ($employeeId === '') {
            throw new TcpException('TCP create employee failed: employeeId is required and client-supplied.');
        }

        if (isset($this->employees[$employeeId])) {
            throw new TcpException("TCP create employee failed: employeeId {$employeeId} already exists.");
        }

        $this->calls[] = ['op' => 'createEmployee', 'args' => ['employeeId' => $employeeId]];

        return $this->employees[$employeeId] = $payload;
    }

    public function updateEmployee(string $employeeId, array $payload): array
    {
        $this->guard('updateEmployee');
        $this->assertWritesEnabled('update employee');

        if (!isset($this->employees[$employeeId])) {
            throw new TcpException("TCP update employee failed: {$employeeId} not found.");
        }

        $this->calls[] = ['op' => 'updateEmployee', 'args' => ['employeeId' => $employeeId]];

        return $this->employees[$employeeId] = array_merge($this->employees[$employeeId], $payload);
    }

    public function listJobCodes(): array
    {
        $this->guard('listJobCodes');

        return $this->jobCodes;
    }

    public function listTimeOffRequests(DateTimeInterface $from, DateTimeInterface $to): array
    {
        $this->guard('listTimeOffRequests');

        return $this->timeOffRequests;
    }

    // ---------------------------------------------------------------- internals

    private const WRITE_OPERATIONS = ['punch', 'createEmployee', 'updateEmployee'];

    private function guard(string $operation): void
    {
        if (isset($this->failures[$operation])) {
            $exception = $this->failures[$operation];
            unset($this->failures[$operation]);

            throw $exception;
        }
    }

    /** Same gate as the real client, so the safety flag is exercised by tests. */
    private function assertWritesEnabled(string $operation): void
    {
        if (!config('tcp.writes_enabled')) {
            throw new TcpException(
                "TCP writes are disabled (TCP_WRITES_ENABLED=false); refusing to {$operation}."
            );
        }
    }
}
