<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A worked shift, as a manager reviews it.
 *
 * NOT one row per punch. TCP stores segments, and punching out and back in
 * closes one and opens another, so a single shift routinely arrives as several.
 * This is the roll-up over them; the raw punches live in `tcp_work_segments`.
 *
 * Three axes, each with exactly one owner — the rule that keeps a background
 * sync from quietly overwriting a person:
 *
 *   time_variance    DERIVED from the data. Anything may recompute it, at any
 *                    time, including the TCP sync.
 *   needs_attention  DERIVED. The grid's "look at this" flag, so review_state
 *                    can stay pure without making managers click through clean
 *                    shifts.
 *   review_state     ASSERTED by a human. Nothing derives it, and no background
 *                    job may overwrite it.
 *   grouping_pinned  ASSERTED by a human. Which segments form this shift, once
 *                    a manager has said so. Safe to honour because grouping is
 *                    ours — TCP has no concept of it, so it contradicts nothing
 *                    TCP holds.
 *
 * The times themselves are never in that list. TCP owns every one of them
 * absolutely and they are only ever recomputed FROM the segments.
 */
class ActualShift extends Model
{
    use SoftDeletes;

    /** Matches the plan on times and label. */
    public const VARIANCE_MATCHES = 'matches';
    /** Has a plan, but differs from it. */
    public const VARIANCE_DIFFERS = 'differs';
    /** No planned counterpart to compare against — ad-hoc coverage. */
    public const VARIANCE_UNPLANNED = 'unplanned';

    /** Recorded by the clock, but no human has passed judgement on it. */
    public const REVIEW_UNREVIEWED = 'unreviewed';
    /** A human says they worked it. */
    public const REVIEW_WORKED = 'worked';
    /** A human says they did not turn up. Never inferred. */
    public const REVIEW_ABSENT = 'absent';

    /** Hand-entered by a manager — no segment behind it came from a clock. */
    public const SOURCE_MANUAL = 'manual';
    /** At least one real punch is behind this, wherever it was made. */
    public const SOURCE_TIMECLOCK = 'timeclock';

    protected $fillable = [
        'store_id', 'employee_id', 'shift_assignment_id', 'shift_date',
        'start_time', 'end_time', 'starts_at_utc', 'ends_at_utc',
        'duration_minutes', 'crosses_midnight', 'is_open', 'label', 'shift_type',
        'time_variance', 'review_state', 'needs_attention', 'grouping_pinned',
        'manager_note', 'reviewed_by_user_id', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'shift_date' => 'date',
            'starts_at_utc' => 'datetime',
            'ends_at_utc' => 'datetime',
            'duration_minutes' => 'integer',
            'crosses_midnight' => 'boolean',
            'is_open' => 'boolean',
            'needs_attention' => 'boolean',
            'grouping_pinned' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    /** The raw punches behind this shift, in the order they were worked. */
    public function segments(): HasMany
    {
        return $this->hasMany(TcpWorkSegment::class, 'actual_shift_id')
            ->orderBy('starts_at_utc');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ShiftAssignment::class, 'shift_assignment_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Where this shift came from — DERIVED, not stored.
     *
     * It used to be a column, which could drift from the segments underneath it.
     * It cannot be derived from "does it have segments", though, because a
     * manager's hand-entry is pushed to TCP and gets one too; the segment's own
     * `origin` is what actually knows.
     *
     * An accessor rather than a plain method, so `$shift->source` still reads
     * exactly as it did when this was a column — and because Eloquent resolves a
     * bare `source()` as a relationship and refuses anything that is not one.
     *
     * Eager-load `segments` wherever this is presented, or every row in a week
     * costs a query.
     */
    protected function source(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->segments->contains(
                fn (TcpWorkSegment $segment) => $segment->isFromClock()
            ) ? self::SOURCE_TIMECLOCK : self::SOURCE_MANUAL,
        );
    }

    /** Shifts still being worked — somebody is on the clock. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('is_open', true);
    }

    /** What the grid should put in front of a manager. */
    public function scopeNeedingAttention(Builder $query): Builder
    {
        return $query->where('needs_attention', true);
    }
}
