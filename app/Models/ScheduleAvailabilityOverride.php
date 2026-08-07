<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ScheduleAvailabilityOverride extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'store_id', 'employee_id', 'scope', 'day_of_week', 'specific_date',
        'all_day', 'start_time', 'end_time', 'reason', 'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'specific_date' => 'date',
            'all_day' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
