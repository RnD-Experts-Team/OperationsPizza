<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The only place day_index is persisted — a template is week-relative by nature. */
class ScheduleTemplateShift extends Model
{
    protected $fillable = [
        'schedule_template_id', 'employee_id', 'position_id', 'day_index',
        'start_time', 'end_time', 'label', 'shift_type', 'note',
    ];

    protected function casts(): array
    {
        return ['day_index' => 'integer'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ScheduleTemplate::class, 'schedule_template_id');
    }
}
