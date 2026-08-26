<?php

namespace App\Services\Humanity;

use App\Services\Humanity\Dto\HumanityShiftPayload;
use App\Services\Humanity\Dto\HumanityShiftResult;
use App\Services\Humanity\Exceptions\HumanityException;
use DateTimeInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HumanityHttpClient implements HumanityClientInterface
{
    // There is no HUMANITY_ENV guard here anymore, deliberately: Humanity has
    // no sandbox, so an "environment" label could only ever describe the ONE
    // live account — and the old label actively inverted safety (sandbox
    // enabled the cron reconciler and skipped the confirm prompt). What
    // protects production now: driver=fake by default, writes_enabled=false
    // by default, and the EXTERNAL_WRITE_ALLOWED_STORES rollout allowlist.
    public function __construct(
        private readonly HumanityTokenManager $tokens,
        private readonly HumanityDateFormatter $dates,
    ) {
    }

    // ---------------------------------------------------------------- catalog

    public function listLocations(?DateTimeInterface $updatedSince = null): array
    {
        $query = [
            'type' => 1,
            // Without this the PRIMARY location is silently excluded, so one
            // store just never appears in the mapping UI.
            'filter' => ['include_primary' => 1],
        ];

        if ($updatedSince) {
            $query['updated_at'] = $this->dates->updatedAt($updatedSince);
        }

        $rows = $this->get('locations', $query, 'list locations');

        return array_values(array_filter(array_map(function ($row) {
            if (!is_array($row)) {
                return null;
            }

            $id = $this->stringId($row, ['id', 'location_id']);

            return $id === null ? null : [
                'id' => $id,
                'name' => (string) ($row['name'] ?? ''),
                'timezone' => $this->nullableString($row['timezone'] ?? null),
                'raw' => $row,
            ];
        }, $rows)));
    }

    public function listPositions(?DateTimeInterface $updatedSince = null): array
    {
        $query = [];

        if ($updatedSince) {
            $query['updated_at'] = $this->dates->updatedAt($updatedSince);
            // include_deleted is only honoured alongside updated_at.
            $query['include_deleted'] = 1;
        }

        $rows = $this->get('positions', $query, 'list positions');

        return array_values(array_filter(array_map(function ($row) {
            if (!is_array($row)) {
                return null;
            }

            $id = $this->stringId($row, ['id', 'position_id']);

            return $id === null ? null : [
                'id' => $id,
                'name' => (string) ($row['name'] ?? $row['title'] ?? "Position {$id}"),
                // location may arrive as a flat id or as a nested {id, name}
                // object, depending on the account — handle both rather than
                // assuming and silently losing it via nullableString(array).
                'location_id' => $this->nullableString(
                    is_array($row['location'] ?? null)
                        ? ($row['location']['id'] ?? null)
                        : ($row['location'] ?? $row['location_id'] ?? null)
                ),
                'is_active' => !$this->truthy($row['deleted'] ?? false),
                'updated_at' => $this->nullableString($row['updated_at'] ?? null),
                'color' => $this->nullableString($row['color'] ?? null),
                'raw' => $row,
            ];
        }, $rows)));
    }

    // -------------------------------------------------------------- employees

    public function listEmployees(bool $includeInactive = false): array
    {
        // Confirmed live 2026-08-26: an unfiltered call already returns the
        // full roster (active + inactive + disabled). Passing disabled=1&
        // inactive=1 does NOT widen that — it narrows the result to ONLY
        // disabled/inactive employees, silently dropping every active one.
        return array_values(array_filter(
            $this->get('employees', [], 'list employees'),
            'is_array'
        ));
    }

    public function getEmployee(string $humanityEmployeeId): ?array
    {
        $data = $this->get("employees/{$humanityEmployeeId}", [], 'get employee', allowMissing: true);

        return $data === [] ? null : $this->firstRow($data);
    }

    public function findEmployeeByEid(string $eid): ?array
    {
        $data = $this->get('employees/by-eid', ['eid' => $eid], 'find employee by eid', allowMissing: true);

        return $data === [] ? null : $this->firstRow($data);
    }

    // ----------------------------------------------------------------- shifts

    public function listShifts(
        string $locationId,
        DateTimeInterface $from,
        DateTimeInterface $to,
        ?string $positionId = null
    ): array {
        // mode drives which filters Humanity honours, and a filter passed in
        // the wrong mode is silently ignored rather than rejected. `schedule`
        // (position) works with confirm|location|schedule|employees.
        $query = [
            'mode' => $positionId === null ? 'location' : 'schedule',
            'start_date' => $this->dates->date($from),
            'end_date' => $this->dates->date($to),
            'location' => $locationId,
        ];

        if ($positionId !== null) {
            $query['schedule'] = $positionId;
        }

        $rows = $this->get('shifts', $query, 'list shifts');

        return array_values(array_filter(array_map(
            fn ($row) => is_array($row) ? $this->toShiftResult($row) : null,
            $rows
        )));
    }

    public function getShift(string $humanityShiftId): ?HumanityShiftResult
    {
        $data = $this->get("shifts/{$humanityShiftId}", [], 'get shift', allowMissing: true, singleObject: true);

        if ($data === []) {
            return null;
        }

        $row = $this->firstRow($data);

        return $row === null ? null : $this->toShiftResult($row);
    }

    public function createShift(HumanityShiftPayload $payload): HumanityShiftResult
    {
        $this->assertWritesEnabled('create shift');

        $body = [
            'schedule' => $payload->positionId, // Humanity's name for Position ID
            'start_date' => $this->dates->date($payload->startsLocal),
            'end_date' => $this->dates->date($payload->endsLocal),
            'start_time' => $this->dates->time($payload->startsLocal),
            'end_time' => $this->dates->time($payload->endsLocal),
            'type' => $payload->open ? 1 : 0,
            // `needed` counts slots still to FILL, not headcount — a Standard
            // (non-open) shift must carry 0 or Humanity rejects the type/needed
            // combination as contradictory.
            'needed' => $payload->open ? $payload->slots : 0,
            // `location` is deliberately NOT sent: it's a remote-location
            // override, not the shift's real location — that comes from
            // `schedule` (the position) on Humanity's side. Sending our
            // locally-cached store location here is what produced
            // "Location with id 'X' does not exist" regardless of which id
            // was tried; $payload->locationId is still resolved and kept
            // locally (humanity_locations, Shift.humanity_location_id) for
            // reconciliation and listShifts()'s read filter.
        ];

        if ($payload->title !== null) {
            $body['title'] = $payload->title;
        }

        if ($payload->note !== null) {
            $body['notes'] = $payload->note;
        }

        // Assigning at creation time halves the request count versus
        // create-then-PUT, which matters a lot for bulk week operations.
        if ($payload->employeeIds !== []) {
            $body['employee_id'] = implode(',', $payload->employeeIds);
        }

        // NOTE: POST is never auto-retried (see request()). A timed-out create
        // is resolved by the caller's read-back probe, not by retrying blind.
        $data = $this->post('shifts', $body, 'create shift', singleObject: true);

        return $this->requireShiftResult($data, 'create shift');
    }

    public function updateShift(string $humanityShiftId, HumanityShiftPayload $payload): HumanityShiftResult
    {
        $this->assertWritesEnabled('update shift');

        $body = [
            'schedule' => $payload->positionId,
            'start_date' => $this->dates->date($payload->startsLocal),
            'end_date' => $this->dates->date($payload->endsLocal),
            'start_time' => $this->dates->time($payload->startsLocal),
            'end_time' => $this->dates->time($payload->endsLocal),
            'type' => $payload->open ? 1 : 0,
            // See createShift(): 0 unless this is an open shift, or Humanity
            // rejects the type/needed combination as contradictory.
            'needed' => $payload->open ? $payload->slots : 0,
            // See createShift(): `location` is a remote-location override,
            // not the shift's real location, and must not be sent here either.

            // NOTE: no update_time/update_type/update_notes/update_schedule
            // flags — an unverified assumption (like `location` and the old
            // `needed`) that a separate confirmed-against-official-docs
            // implementation of this same API never uses. If updates start
            // silently no-op'ing again without them, that theory needs
            // revisiting, but they aren't sent for now.
        ];

        // Conditional, matching createShift(): sending `title`/`notes` as
        // null (rather than omitting them) is not the same as "leave this
        // field alone" to Humanity.
        if ($payload->title !== null) {
            $body['title'] = $payload->title;
        }

        if ($payload->note !== null) {
            $body['notes'] = $payload->note;
        }

        $data = $this->put("shifts/{$humanityShiftId}", $body, 'update shift', singleObject: true);

        return $this->requireShiftResult($data, 'update shift', $humanityShiftId);
    }

    public function deleteShift(string $humanityShiftId): void
    {
        $this->assertWritesEnabled('delete shift');

        // One at a time — Humanity has no bulk delete, and looping this is
        // exactly the pattern that trips throttle status 91.
        $this->request('delete', "shifts/{$humanityShiftId}", [], 'delete shift');
    }

    public function assignEmployees(string $humanityShiftId, array $humanityEmployeeIds, bool $force = false): HumanityShiftResult
    {
        $this->assertWritesEnabled('assign employees');

        $data = $this->put("shifts/{$humanityShiftId}", [
            'add' => implode(',', $humanityEmployeeIds),
            'force' => $force ? 1 : 0,
            'update_staff' => 1,
        ], 'assign employees', singleObject: true);

        return $this->requireShiftResult($data, 'assign employees', $humanityShiftId);
    }

    public function unassignEmployees(string $humanityShiftId, array $humanityEmployeeIds): HumanityShiftResult
    {
        $this->assertWritesEnabled('unassign employees');

        $data = $this->put("shifts/{$humanityShiftId}", [
            'remove' => implode(',', $humanityEmployeeIds),
            'update_staff' => 1,
        ], 'unassign employees', singleObject: true);

        return $this->requireShiftResult($data, 'unassign employees', $humanityShiftId);
    }

    // ------------------------------------------------------- leave & timeclock

    public function listLeave(DateTimeInterface $from, DateTimeInterface $to, ?string $locationId = null): array
    {
        $results = [];
        $page = 1;

        // /leaves is the one list endpoint with real pagination (page + limit,
        // default 100).
        do {
            $query = [
                'start_date' => $this->dates->date($from),
                'end_date' => $this->dates->date($to),
                'mode' => 'all_requested',
                'page' => $page,
                'limit' => 100,
            ];

            if ($locationId !== null) {
                $query['location'] = $locationId;
            }

            $rows = $this->get('leaves', $query, 'list leave');
            $rows = array_values(array_filter($rows, 'is_array'));
            $results = array_merge($results, $rows);
            $page++;
        } while (count($rows) === 100 && $page <= 50);

        return $results;
    }

    public function listTimeClocks(DateTimeInterface $from, DateTimeInterface $to, ?string $locationId = null): array
    {
        $query = [
            'start_date' => $this->dates->date($from),
            'end_date' => $this->dates->date($to),
        ];

        if ($locationId !== null) {
            $query['location'] = $locationId;
        }

        return array_values(array_filter($this->get('timeclocks', $query, 'list timeclocks'), 'is_array'));
    }

    // ------------------------------------------------------------- HTTP plumbing

    private function get(string $path, array $query, string $context, bool $allowMissing = false, bool $singleObject = false): array
    {
        return $this->request('get', $path, $query, $context, $allowMissing, $singleObject);
    }

    private function post(string $path, array $body, string $context, bool $singleObject = false): array
    {
        return $this->request('post', $path, $body, $context, singleObject: $singleObject);
    }

    private function put(string $path, array $body, string $context, bool $singleObject = false): array
    {
        return $this->request('put', $path, $body, $context, singleObject: $singleObject);
    }

    private function request(
        string $method,
        string $path,
        array $payload,
        string $context,
        bool $allowMissing = false,
        bool $singleObject = false,
    ): array {
        $response = $this->send($method, $path, $payload);

        // The token may have been revoked mid-flight; re-auth once.
        if ($response->isAuthFailure()) {
            $this->tokens->forget();
            $response = $this->send($method, $path, $payload);
        }

        if ($allowMissing && ($response->httpStatus === 404 || in_array($response->humanityStatus, [15], true))) {
            return [];
        }

        $response->throwIfFailed($context);

        $data = $response->data();

        // A single shift object carries its OWN `employees` key (its
        // roster) — unwrapCollection()'s wrapper-key sniffing (meant for
        // list envelopes like {"shifts":[...]}) matches that key and
        // silently returns the roster instead of the shift, so toShiftResult()
        // ends up reading an EMPLOYEE's id as the shift's id. get/create/
        // update/assign/unassign shift all return one object, never a
        // named-wrapper list, so they must skip that sniffing entirely.
        return $singleObject ? $this->singleRow($data) : $this->unwrapCollection($data);
    }

    /** @return array<int, array<string, mixed>> */
    private function singleRow(array $data): array
    {
        if ($data !== [] && !array_is_list($data)) {
            return [$data];
        }

        return $data;
    }

    private function send(string $method, string $path, array $payload): HumanityResponse
    {
        $url = rtrim((string) config('humanity.base_url'), '/') . '/' . ltrim($path, '/');

        $request = $this->pendingRequest($method);

        $response = $method === 'get' || $method === 'delete'
            ? $request->{$method}($url, $payload)
            : $request->{$method}($url, $payload);

        $parsed = HumanityResponse::fromHttp($response);

        if (!$parsed->isSuccess()) {
            Log::warning('Humanity call failed', [
                'method' => strtoupper($method),
                'path' => $path,
                'http_status' => $parsed->httpStatus,
                'humanity_status' => $parsed->humanityStatus,
                'message' => $parsed->message(),
            ]);
        }

        return $parsed;
    }

    private function pendingRequest(string $method): PendingRequest
    {
        $request = Http::withToken($this->tokens->accessToken())
            ->acceptJson()
            ->timeout((int) config('humanity.timeout', 10));

        // Retrying a POST is how duplicate shifts get created: Humanity has no
        // idempotency key, so a timed-out create may well have landed. Reads
        // and idempotent verbs may retry freely.
        if ($method !== 'post') {
            $retries = (int) config('humanity.retries', 2);

            if ($retries > 0) {
                $request = $request->retry($retries, (int) config('humanity.retry_ms', 250), throw: false);
            }
        }

        return $request;
    }

    /** Responses wrap rows inconsistently; normalise to a plain list. */
    private function unwrapCollection(array $data): array
    {
        foreach (['shifts', 'employees', 'locations', 'positions', 'leaves', 'timeclocks'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return array_values($data[$key]);
            }
        }

        // A single associative row, not a list.
        if ($data !== [] && !array_is_list($data)) {
            return [$data];
        }

        return $data;
    }

    private function firstRow(array $data): ?array
    {
        $first = $data[0] ?? null;

        return is_array($first) ? $first : null;
    }

    private function requireShiftResult(array $data, string $context, ?string $fallbackId = null): HumanityShiftResult
    {
        $row = $this->firstRow($data);

        if ($row === null) {
            if ($fallbackId !== null) {
                $existing = $this->getShift($fallbackId);

                if ($existing !== null) {
                    return $existing;
                }
            }

            throw new HumanityException("Humanity {$context} returned no shift payload.");
        }

        return $this->toShiftResult($row);
    }

    private function toShiftResult(array $row): HumanityShiftResult
    {
        $employees = [];

        foreach ((array) ($row['employees'] ?? []) as $employee) {
            if (is_array($employee)) {
                $id = $this->stringId($employee, ['id', 'employee_id', 'user_id']);
            } else {
                $id = $this->nullableString($employee);
            }

            if ($id !== null) {
                $employees[] = $id;
            }
        }

        return new HumanityShiftResult(
            shiftId: (string) ($this->stringId($row, ['id', 'shift_id']) ?? ''),
            positionId: $this->nullableString($row['schedule'] ?? $row['position_id'] ?? null),
            locationId: $this->nullableString($row['location'] ?? $row['location_id'] ?? null),
            startDate: $this->dateOnly($row['start_date'] ?? $row['start'] ?? null),
            startTime: $this->timeOnly($row['start_time'] ?? $row['start'] ?? null),
            endDate: $this->dateOnly($row['end_date'] ?? $row['end'] ?? null),
            endTime: $this->timeOnly($row['end_time'] ?? $row['end'] ?? null),
            employeeIds: $employees,
            title: $this->nullableString($row['title'] ?? null),
            note: $this->nullableString($row['notes'] ?? $row['note'] ?? null),
            slots: (int) ($row['needed'] ?? 1) ?: 1,
            published: $this->truthy($row['published'] ?? $row['confirmed'] ?? false),
            raw: $row,
        );
    }

    private function dateOnly(mixed $value): ?string
    {
        return $this->dates->parse($value)?->format('Y-m-d');
    }

    private function timeOnly(mixed $value): ?string
    {
        if (is_string($value) && preg_match('/^\d{1,2}:\d{2}/', $value)) {
            return substr(str_pad($value, 5, '0', STR_PAD_LEFT), 0, 5);
        }

        return $this->dates->parse($value)?->format('H:i');
    }

    private function stringId(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->nullableString($row[$key] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'yes'], true);
    }

    private function assertWritesEnabled(string $operation): void
    {
        if (!config('humanity.writes_enabled')) {
            throw new HumanityException(
                "Humanity writes are disabled (HUMANITY_WRITES_ENABLED=false); refusing to {$operation}."
            );
        }
    }
}
