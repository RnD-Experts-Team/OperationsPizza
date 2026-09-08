<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Never written to Humanity. Written through to TCP, which owns worked hours.
 *
 * Two independent axes, deliberately NOT one `status` enum — see
 * 2026_09_08_000001_split_actual_shift_status_axes:
 *
 *   time_variance  DERIVED from the data. Anything may recompute it at any
 *                  time, including the background TCP sync.
 *   review_state   ASSERTED by a human. Nothing derives it, and no background
 *                  job may overwrite it.
 *
 * Keeping them apart is what stops an automated sync from quietly turning a
 * manager's no-show back into "worked as planned".
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

    protected $fillable = [
        'store_id', 'employee_id', 'shift_assignment_id', 'shift_date',
        'start_time', 'end_time', 'starts_at_utc', 'ends_at_utc',
        'duration_minutes', 'crosses_midnight', 'label', 'shift_type',
        'time_variance', 'review_state', 'note', 'source', 'humanity_timeclock_id',
        'tcp_work_segment_id', 'has_missed_punch',
        'actual_punch_in_at', 'actual_punch_out_at',
        'reviewed_by_user_id', 'reviewed_at',
    ];

    /**
     * The pre-split `status` string, for the API.
     *
     * Clients branch on confirmed/modified/absent/added, and the two axes
     * collapse back onto it exactly — so nothing had to change when the columns
     * were split. New work should read `time_variance` and `review_state`
     * instead; this exists so adopting them can happen whenever it suits,
     * rather than in lockstep with a migration.
     */
    public function legacyStatus(): string
    {
        if ($this->review_state === self::REVIEW_ABSENT) {
            return 'absent';
        }

        return match ($this->time_variance) {
            self::VARIANCE_UNPLANNED => 'added',
            self::VARIANCE_DIFFERS => 'modified',
            default => 'confirmed',
        };
    }

    protected function casts(): array
    {
        return [
            'shift_date' => 'date',
            'starts_at_utc' => 'datetime',
            'ends_at_utc' => 'datetime',
            'duration_minutes' => 'integer',
            'crosses_midnight' => 'boolean',
            'has_missed_punch' => 'boolean',
            'actual_punch_in_at' => 'datetime',
            'actual_punch_out_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ShiftAssignment::class, 'shift_assignment_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
