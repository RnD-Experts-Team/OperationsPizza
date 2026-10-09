<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Mirror of a Humanity shift. Humanity is the source of truth; a row exists
 * here only because a write to Humanity succeeded, or because the reconciler
 * found one upstream that we didn't have.
 *
 * The one exception is `sync_status`. Humanity's rate limit is unpublished and
 * account-wide, so a busy publish day can throttle us mid-schedule. A throttle
 * is not the manager's mistake and cannot be fixed by redoing the action, so
 * the shift is written here with sync_status=pending and carried to Humanity
 * afterwards by humanity:sync-pending-shifts. Every OTHER failure still
 * rejects the write outright and leaves nothing behind.
 */
class Shift extends Model
{
    use SoftDeletes;

    public const ORIGIN_OPERATIONS = 'operations';
    public const ORIGIN_HUMANITY = 'humanity';
    public const ORIGIN_RECONCILER = 'reconciler';

    /** Humanity has this shift as we last wrote it. */
    public const SYNC_SYNCED = 'synced';
    /** Written locally, still owed to Humanity. */
    public const SYNC_PENDING = 'pending';
    /** Retries exhausted; a human has to look at it. */
    public const SYNC_PARKED = 'parked';

    protected $fillable = [
        'store_id',
        'humanity_location_id',
        'humanity_position_id',
        'humanity_shift_id',
        'shift_date',
        'start_time',
        'end_time',
        'starts_at_utc',
        'ends_at_utc',
        'duration_minutes',
        'crosses_midnight',
        'label',
        'shift_type',
        'note',
        'slots',
        'is_published',
        'origin',
        'recurring_group_id',
        'created_by_user_id',
        'humanity_updated_at',
        'humanity_hash',
        'last_reconciled_at',
        'sync_status',
        'sync_attempts',
        'sync_next_attempt_at',
        'sync_last_error',
        'sync_parked_at',
    ];

    protected function casts(): array
    {
        return [
            'shift_date' => 'date',
            'starts_at_utc' => 'datetime',
            'ends_at_utc' => 'datetime',
            'duration_minutes' => 'integer',
            'crosses_midnight' => 'boolean',
            'slots' => 'integer',
            'is_published' => 'boolean',
            'humanity_updated_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
            'sync_attempts' => 'integer',
            'sync_next_attempt_at' => 'datetime',
            'sync_parked_at' => 'datetime',
        ];
    }

    /** Owed to Humanity — either waiting for a retry, or parked for a human. */
    public function isAwaitingHumanitySync(): bool
    {
        return in_array($this->sync_status, [self::SYNC_PENDING, self::SYNC_PARKED], true);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }

    /**
     * Overlap is always computed on UTC instants. Wall-clock comparison silently
     * misses a 22:00→02:00 shift colliding with the next day's 01:00→09:00.
     */
    public function scopeOverlapping(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $query->where('starts_at_utc', '<', $to)
            ->where('ends_at_utc', '>', $from);
    }

    public function scopeForStore(Builder $query, int $storeId): Builder
    {
        return $query->where('store_id', $storeId);
    }

    /**
     * Shifts that START inside the window (inclusive of both ends).
     *
     * whereDate(), not whereBetween(): Laravel's `date` cast writes
     * "Y-m-d H:i:s", which MySQL truncates into a real DATE but SQLite stores
     * verbatim — so a plain string comparison would treat
     * "2026-08-10 00:00:00" as greater than "2026-08-10" and silently drop the
     * last day of every week.
     */
    public function scopeInDateRange(Builder $query, string $from, string $to): Builder
    {
        return $query->whereDate('shift_date', '>=', $from)
            ->whereDate('shift_date', '<=', $to);
    }
}
