<?php

namespace App\Models;

use App\Services\Tcp\Dto\TcpWorkSegment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The current clock state of one employee.
 *
 * Owned by this service. TCP is still the system of record for worked time —
 * this is our durable record of what we last knew, so a cache flush costs
 * nothing and "who is on the clock" is a query rather than a vendor call.
 */
class EmployeeClockState extends Model
{
    public const STATUS_CLOCKED_OUT = 'clocked_out';
    public const STATUS_CLOCKED_IN = 'clocked_in';
    public const STATUS_ON_BREAK = 'on_break';

    protected $fillable = [
        'employee_id', 'store_id', 'tcp_employee_id', 'status',
        'tcp_work_segment_id', 'clock_in_at', 'break_started_at',
        'open_segment', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'clock_in_at' => 'datetime',
            'break_started_at' => 'datetime',
            'open_segment' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * On the clock right now.
     *
     * A break closes the worked segment in TCP, so on_break is deliberately
     * NOT clocked in — it matches what TCP would report.
     */
    public function isClockedIn(): bool
    {
        return $this->status === self::STATUS_CLOCKED_IN;
    }

    /**
     * Is this row recent enough to answer from instead of asking TCP?
     *
     * Reuses the same trust window the cache-only implementation used, so
     * persisting the state did not quietly widen how stale an answer may be:
     * a punch made at a physical clock is still invisible for at most this long.
     */
    public function isFresh(?int $ttlSeconds = null): bool
    {
        $ttlSeconds ??= (int) config('tcp.clock_state_ttl_seconds', 900);

        return $this->last_synced_at !== null
            && $this->last_synced_at->greaterThan(CarbonImmutable::now()->subSeconds($ttlSeconds));
    }

    /** The open segment as TCP last reported it, or null when clocked out. */
    public function openSegment(): ?TcpWorkSegment
    {
        if (!$this->isClockedIn() || !is_array($this->open_segment) || $this->open_segment === []) {
            return null;
        }

        return TcpWorkSegment::fromArray($this->open_segment);
    }
}
