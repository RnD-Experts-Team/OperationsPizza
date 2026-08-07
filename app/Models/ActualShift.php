<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Owned by this service. Never written to Humanity. */
class ActualShift extends Model
{
    use SoftDeletes;

    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_MODIFIED = 'modified';
    public const STATUS_ABSENT = 'absent';
    public const STATUS_ADDED = 'added';

    protected $fillable = [
        'store_id', 'employee_id', 'shift_assignment_id', 'shift_date',
        'start_time', 'end_time', 'starts_at_utc', 'ends_at_utc',
        'duration_minutes', 'crosses_midnight', 'label', 'shift_type',
        'status', 'note', 'source', 'humanity_timeclock_id',
        'reviewed_by_user_id', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'shift_date' => 'date',
            'starts_at_utc' => 'datetime',
            'ends_at_utc' => 'datetime',
            'duration_minutes' => 'integer',
            'crosses_midnight' => 'boolean',
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
