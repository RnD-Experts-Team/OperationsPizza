<?php

namespace App\Services\Humanity;

use App\Services\Humanity\Exceptions\HumanityAuthException;
use App\Services\Humanity\Exceptions\HumanityException;
use App\Services\Humanity\Exceptions\HumanityRateLimitException;
use Illuminate\Http\Client\Response;

/**
 * Humanity's error protocol lives in the BODY, not the HTTP status.
 *
 * The v2 reference documents only HTTP 200 and 400, and the official SDK sends
 * suppress_response_codes=1 on every request — so a failure arrives as HTTP 200
 * with `status: 91`. Anything that trusts $response->successful() will happily
 * treat a throttle, a permission denial, or a banned API key as success.
 */
class HumanityResponse
{
    public const SUCCESS = 1;
    public const THROTTLED = 91;

    /** Documented application status codes. */
    private const MESSAGES = [
        -3 => 'Flagged API Key - Permanently Banned',
        -2 => 'Flagged API Key - Too Many invalid access attempts',
        -1 => 'Flagged API Key - Temporarily Disabled',
        2 => 'Invalid API key',
        3 => 'Invalid token key - Please re-authenticate',
        4 => 'Invalid Method',
        5 => 'Invalid Module',
        6 => 'Invalid Action',
        7 => 'Authentication Failed - You do not have permissions',
        8 => 'Missing parameters',
        9 => 'Invalid parameters (bad type)',
        10 => 'Extra parameters (unallowed)',
        12 => 'Create Failed',
        13 => 'Update Failed',
        14 => 'Delete Failed',
        15 => 'Get Failed',
        20 => 'Incorrect Permissions',
        90 => 'Suspended API key',
        91 => 'Throttle exceeded - max allowed requests. Try again later',
        98 => 'Bad API Parameters',
        99 => 'Service Offline',
    ];

    /** Auth/permission failures — retrying without changing anything won't help. */
    private const AUTH_STATUSES = [-3, -2, -1, 2, 3, 7, 20, 90];

    public function __construct(
        public readonly ?int $humanityStatus,
        public readonly int $httpStatus,
        public readonly array $body,
    ) {
    }

    public static function fromHttp(Response $response): self
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        $status = $body['status'] ?? null;

        return new self(
            is_numeric($status) ? (int) $status : null,
            $response->status(),
            $body,
        );
    }

    public function isSuccess(): bool
    {
        // A missing `status` on an HTTP 2xx is treated as success: not every
        // endpoint is documented to return one, and failing closed here would
        // break reads that actually worked.
        if ($this->humanityStatus === null) {
            return $this->httpStatus >= 200 && $this->httpStatus < 300;
        }

        return $this->humanityStatus === self::SUCCESS;
    }

    /** The `data` envelope, or the whole body when there isn't one. */
    public function data(): array
    {
        $data = $this->body['data'] ?? null;

        if (is_array($data)) {
            return $data;
        }

        return $this->body;
    }

    public function throwIfFailed(string $context): void
    {
        if ($this->isSuccess()) {
            return;
        }

        $message = $this->message();
        $full = "Humanity {$context} failed: {$message}";

        if ($this->humanityStatus === self::THROTTLED) {
            throw new HumanityRateLimitException($full, $this->humanityStatus, $this->httpStatus, $this->body);
        }

        if (in_array($this->humanityStatus, self::AUTH_STATUSES, true) || $this->httpStatus === 401) {
            throw new HumanityAuthException($full, $this->humanityStatus, $this->httpStatus, $this->body);
        }

        throw new HumanityException($full, $this->humanityStatus, $this->httpStatus, $this->body);
    }

    public function message(): string
    {
        foreach (['error', 'message', 'error_description'] as $key) {
            $value = $this->body[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        if ($this->humanityStatus !== null && isset(self::MESSAGES[$this->humanityStatus])) {
            return self::MESSAGES[$this->humanityStatus] . " (status {$this->humanityStatus})";
        }

        return "HTTP {$this->httpStatus}, status " . ($this->humanityStatus ?? 'none');
    }

    public function isAuthFailure(): bool
    {
        return $this->httpStatus === 401
            || in_array($this->humanityStatus, [2, 3], true);
    }
}
