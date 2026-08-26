<?php

namespace App\Services\Tcp;

use App\Services\Tcp\Dto\TcpPunch;
use App\Services\Tcp\Dto\TcpWorkSegment;
use App\Services\Tcp\Exceptions\TcpAuthException;
use App\Services\Tcp\Exceptions\TcpException;
use App\Services\Tcp\Exceptions\TcpRateLimitException;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TcpHttpClient implements TcpClientInterface
{
    // No TCP_ENV guard, same reasoning as HumanityHttpClient: TCP has no
    // sandbox, so the label could only describe the one live account. Safety
    // is driver=fake + writes_enabled=false defaults + the store allowlist.
    public function __construct(
        private readonly TcpTokenManager $tokens,
        private readonly TcpRateLimiter $limiter,
    ) {
    }

    public function ping(): bool
    {
        try {
            $this->request('get', 'ping', [], 'ping', interactive: true);

            return true;
        } catch (\Throwable $e) {
            Log::warning('TCP ping failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    // ---------------------------------------------------------------- clocking

    public function punch(array $punches): array
    {
        $this->assertWritesEnabled('punch');

        if ($punches === []) {
            return [];
        }

        $body = array_map(
            fn (TcpPunch $punch) => $punch->toPayload(),
            array_values($punches)
        );

        // Interactive: someone is standing at a clock waiting. Allowed to use
        // the reserve that background syncs must leave alone.
        $data = $this->request('post', 'punches', $body, 'punch', interactive: true);

        return $this->toWorkSegments($data);
    }

    // ------------------------------------------------------------ worked hours

    public function listWorkSegments(
        DateTimeInterface $from,
        DateTimeInterface $to,
        array $employeeIds = [],
        ?DateTimeInterface $updatedSince = null,
    ): array {
        $maxIds = (int) config('tcp.max_ids_per_request', 20);

        // employeeIds is capped at 20 and TCP does not error on more — it just
        // silently ignores the overflow, which would look like missing hours.
        $chunks = $employeeIds === []
            ? [[]]
            : array_chunk(array_values(array_unique($employeeIds)), $maxIds);

        $segments = [];

        foreach ($chunks as $chunk) {
            $segments = array_merge($segments, $this->listWorkSegmentChunk($from, $to, $chunk, $updatedSince));
        }

        return $segments;
    }

    private function listWorkSegmentChunk(
        DateTimeInterface $from,
        DateTimeInterface $to,
        array $employeeIds,
        ?DateTimeInterface $updatedSince,
    ): array {
        $perPage = (int) config('tcp.max_per_page', 1000);
        $page = 1;
        $segments = [];

        do {
            $query = [
                'startDate' => $this->dateTime($from),
                'stopDate' => $this->dateTime($to),
                'perPage' => $perPage,
                'page' => $page,
                'includeBreakLength' => 'true',
            ];

            if ($employeeIds !== []) {
                $query['employeeIds'] = implode(',', $employeeIds);
            }

            // The delta filter. Humanity has no equivalent for shifts, which is
            // why its reconciler must re-read a whole rolling window; here we
            // can ask only for what changed and spend a fraction of the quota.
            if ($updatedSince !== null) {
                $query['updatedOnStart'] = $this->dateTime($updatedSince);
            }

            $rows = $this->request('get', 'worksegments', $query, 'list work segments');
            $segments = array_merge($segments, $this->toWorkSegments($rows));

            $page++;
            // Fewer than a full page means the end — the documented way to
            // detect it, since no total count is returned.
        } while (count($rows) === $perPage && $page <= 100);

        return $segments;
    }

    public function listCalculationChanges(?DateTimeInterface $since = null): array
    {
        $query = ['perPage' => (int) config('tcp.max_per_page', 1000)];

        if ($since !== null) {
            $query['sinceLastDateTime'] = $this->dateTime($since);
        }

        $rows = $this->request('get', 'calculationchanges', $query, 'list calculation changes');

        $allChanged = false;
        $employeeIds = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if ($this->truthy($row['allEmployeesChanged'] ?? false)) {
                $allChanged = true;
            }

            foreach ((array) ($row['employees'] ?? []) as $employee) {
                $id = is_array($employee)
                    ? $this->str($employee['employeeId'] ?? null)
                    : $this->str($employee);

                if ($id !== null) {
                    $employeeIds[] = $id;
                }
            }
        }

        return [
            'all_changed' => $allChanged,
            'employee_ids' => array_values(array_unique($employeeIds)),
        ];
    }

    public function getWorkSegment(string $id): ?TcpWorkSegment
    {
        $rows = $this->request('get', "worksegments/{$id}", [], 'get work segment', allowMissing: true);

        $segments = $this->toWorkSegments($rows);

        return $segments[0] ?? null;
    }

    // --------------------------------------------------------------- employees

    public function listEmployees(array $filters = []): array
    {
        $perPage = 50;
        $page = 1;
        $employees = [];

        do {
            $rows = $this->request('get', 'employees', $filters + [
                'perPage' => $perPage,
                'page' => $page,
            ], 'list employees');

            $employees = array_merge($employees, array_values(array_filter($rows, 'is_array')));
            $page++;
        } while (count($rows) === $perPage && $page <= 200);

        return $employees;
    }

    public function getEmployee(string $employeeId): ?array
    {
        $rows = $this->request('get', "employees/{$employeeId}", [], 'get employee', allowMissing: true);

        $first = $rows[0] ?? null;

        return is_array($first) ? $first : null;
    }

    public function createEmployee(array $payload): array
    {
        $this->assertWritesEnabled('create employee');

        $rows = $this->request('post', 'employees', [$payload], 'create employee');

        $first = $rows[0] ?? null;

        if (!is_array($first)) {
            throw new TcpException('TCP create employee returned no record.');
        }

        return $first;
    }

    public function updateEmployee(string $employeeId, array $payload): array
    {
        $this->assertWritesEnabled('update employee');

        $rows = $this->request('put', "employees/{$employeeId}", $payload, 'update employee');

        $first = $rows[0] ?? null;

        return is_array($first) ? $first : ['employeeId' => $employeeId];
    }

    // --------------------------------------------------------------- catalog

    public function listLocations(): array
    {
        return $this->paginate('locations', [], 'list locations');
    }

    public function listJobCodes(): array
    {
        // Paged: the account has hundreds of per-store codes, and TCP has been
        // observed serving 50-row pages regardless of the requested perPage —
        // a single unpaged call silently truncates the catalog.
        return $this->paginate('jobcodes', [], 'list job codes');
    }

    /** @return array<int, array<string, mixed>> */
    private function paginate(string $path, array $filters, string $context): array
    {
        $perPage = 50;
        $page = 1;
        $all = [];

        do {
            $rows = $this->request('get', $path, $filters + [
                'perPage' => $perPage,
                'page' => $page,
            ], $context);

            $all = array_merge($all, array_values(array_filter($rows, 'is_array')));
            $page++;
        } while (count($rows) === $perPage && $page <= 200);

        return $all;
    }

    // ---------------------------------------------------------------- time off

    public function listTimeOffRequests(DateTimeInterface $from, DateTimeInterface $to): array
    {
        return array_values(array_filter(
            $this->request('get', 'employeerequests', [
                'startDate' => $this->dateTime($from),
                'stopDate' => $this->dateTime($to),
                'perPage' => 1000,
            ], 'list time off requests'),
            'is_array'
        ));
    }

    // ------------------------------------------------------------- HTTP plumbing

    private function request(
        string $method,
        string $path,
        array $payload,
        string $context,
        bool $allowMissing = false,
        bool $interactive = false,
    ): array {
        // Refuse locally before spending a call. A 429 can carry a cooldown,
        // so collecting one makes the rest of the day worse.
        $this->limiter->hit($interactive);

        $response = $this->send($method, $path, $payload);

        // A revoked or rotated token: re-auth once and retry.
        if ($response->status() === 401) {
            $this->tokens->forget();
            $this->limiter->hit($interactive);
            $response = $this->send($method, $path, $payload);
        }

        if ($allowMissing && $response->status() === 404) {
            return [];
        }

        if ($response->status() === 429) {
            $this->limiter->recordRemoteRejection();

            throw new TcpRateLimitException(
                "TCP rate limit hit on {$context}.",
                retryAfterSeconds: (int) ($response->header('Retry-After') ?: 60),
                httpStatus: 429,
            );
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if (!$response->successful()) {
            $this->throwForStatus($response->status(), $body, $context);
        }

        // TCP reports partial failures in `errors` while still returning 200/201,
        // so a successful status alone is not proof the write landed.
        $errors = $body['errors'] ?? [];

        if (is_array($errors) && $errors !== []) {
            throw new TcpException(
                "TCP {$context} reported errors: " . $this->summariseErrors($errors),
                httpStatus: $response->status(),
                errors: $errors,
                requestId: data_get($body, 'meta.requestId'),
            );
        }

        $data = $body['data'] ?? $body;

        if (!is_array($data)) {
            return [];
        }

        // A single object rather than a list.
        return array_is_list($data) ? $data : [$data];
    }

    private function send(string $method, string $path, array $payload)
    {
        $url = rtrim((string) config('tcp.base_url'), '/') . '/' . ltrim($path, '/');

        $request = $this->pendingRequest($method);

        return match ($method) {
            'get' => $request->get($url, $payload),
            'delete' => $request->delete($url, $payload),
            default => $request->{$method}($url, $payload),
        };
    }

    private function pendingRequest(string $method): PendingRequest
    {
        $request = Http::withToken($this->tokens->accessToken())
            ->withHeaders(array_filter([
                'x-api-key' => config('tcp.api_key'),
                'X-Tcp-CompanyId' => (string) config('tcp.company_id', '1'),
            ]))
            ->acceptJson()
            ->timeout((int) config('tcp.timeout', 10));

        // Never auto-retry a punch: TCP has no idempotency key, and a retried
        // clockIn that actually landed creates a second open segment. Reads and
        // idempotent verbs may retry.
        if ($method !== 'post') {
            $retries = (int) config('tcp.retries', 2);

            if ($retries > 0) {
                $request = $request->retry($retries, (int) config('tcp.retry_ms', 250), throw: false);
            }
        }

        return $request;
    }

    private function throwForStatus(int $status, array $body, string $context): void
    {
        $message = $this->summariseErrors($body['errors'] ?? [])
            ?: (is_string($body['message'] ?? null) ? $body['message'] : "HTTP {$status}");

        $full = "TCP {$context} failed: {$message}";
        $requestId = data_get($body, 'meta.requestId');

        Log::warning('TCP call failed', [
            'context' => $context,
            'http_status' => $status,
            'request_id' => $requestId,
            'message' => $message,
        ]);

        if (in_array($status, [401, 403], true)) {
            throw new TcpAuthException($full, $status, (array) ($body['errors'] ?? []), $requestId);
        }

        throw new TcpException($full, $status, (array) ($body['errors'] ?? []), $requestId);
    }

    private function summariseErrors(mixed $errors): string
    {
        if (!is_array($errors) || $errors === []) {
            return '';
        }

        return implode('; ', array_map(function ($error) {
            if (is_string($error)) {
                return $error;
            }

            if (!is_array($error)) {
                return 'unknown error';
            }

            return trim(implode(' ', array_filter([
                $error['field'] ?? null,
                $error['message'] ?? $error['description'] ?? null,
            ])));
        }, array_slice($errors, 0, 5)));
    }

    /** @return array<int, TcpWorkSegment> */
    private function toWorkSegments(array $rows): array
    {
        return array_values(array_filter(array_map(function ($row) {
            if (!is_array($row)) {
                return null;
            }

            $employeeId = $this->str($row['employeeId'] ?? null);

            if ($employeeId === null) {
                return null;
            }

            // CONFIRMED live 2026-08-26: a punch's own POST response echoes
            // what was sent (employeeId, timeIn/timeOut, jobCodeId) but does
            // NOT carry the segment's assigned id — that only appears on a
            // later GET /worksegments. Requiring `id` here silently dropped
            // every successful punch. Empty string, not null, matching how
            // HumanityHttpClient::toShiftResult() already handles a missing
            // id elsewhere in this codebase — callers that need the real id
            // (TcpClockService::send()) fall back to a targeted live lookup.
            $id = $this->str($row['id'] ?? null) ?? '';

            return new TcpWorkSegment(
                id: $id,
                employeeId: $employeeId,
                jobCodeId: $this->str($row['jobCodeId'] ?? null),
                timeIn: $this->str($row['timeIn'] ?? null),
                timeOut: $this->str($row['timeOut'] ?? null),
                actualTimeIn: $this->str($row['actualTimeIn'] ?? null),
                actualTimeOut: $this->str($row['actualTimeOut'] ?? null),
                missedInPunch: $this->truthy($row['missedInPunch'] ?? false),
                missedOutPunch: $this->truthy($row['missedOutPunch'] ?? false),
                breakLength: $this->str($row['breakLength'] ?? null),
                shiftNotes: array_values(array_filter((array) ($row['shiftNotes'] ?? []), 'is_string')),
                updatedOn: $this->str($row['updatedOnDateTime'] ?? null),
                raw: $row,
            );
        }, $rows)));
    }

    /** TCP wants local wall clock with no offset — it applies the system timezone. */
    private function dateTime(DateTimeInterface $value): string
    {
        return CarbonImmutable::instance($value)->format('Y-m-d\TH:i:s');
    }

    private function str(mixed $value): ?string
    {
        if ($value === null || is_array($value) || $value === '') {
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
        return in_array($value, [true, 1, '1', 'true', 'True'], true);
    }

    private function assertWritesEnabled(string $operation): void
    {
        if (!config('tcp.writes_enabled')) {
            throw new TcpException(
                "TCP writes are disabled (TCP_WRITES_ENABLED=false); refusing to {$operation}."
            );
        }
    }
}
