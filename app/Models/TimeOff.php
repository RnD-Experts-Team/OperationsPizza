<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Mirror of Humanity leave. Stored as a range; expanded per-day at the API edge. */
class TimeOff extends Model
{
    use SoftDeletes;

    protected $table = 'time_off';

    protected $fillable = [
        'store_id', 'employee_id', 'humanity_leave_id', 'start_date', 'end_date',
        'all_day', 'start_time', 'end_time', 'type', 'label', 'status', 'note', 'origin',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'all_day' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** whereDate() for driver-independent DATE comparison — see Shift::scopeInDateRange. */
    public function scopeOverlappingDates(Builder $query, string $from, string $to): Builder
    {
        return $query->whereDate('start_date', '<=', $to)
            ->whereDate('end_date', '>=', $from);
    }
}
