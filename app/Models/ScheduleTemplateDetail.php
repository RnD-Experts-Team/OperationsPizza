<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleTemplateDetail extends Model
{
    protected $fillable = [
        'schedule_template_id',
        'employee_id',
        'day_of_week',
        'start_time',
        'end_time',
        'skill_id'
    ];

    public function template()
    {
        return $this->belongsTo(ScheduleTemplate::class, 'schedule_template_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function skill()
    {
        return $this->belongsTo(Skill::class);
    }
}