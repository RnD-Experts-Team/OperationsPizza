<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Schedule extends Model
{
    protected $fillable = [
        'employee_id','schedule_week_id','date',
        'start_time','end_time',
        'actual_start_time','actual_end_time',
        'skill_id','edited_by'
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function skill()
    {
        return $this->belongsTo(Skill::class);
    }

    public function masterSchedule()
    {
        return $this->belongsTo(MasterSchedule::class, 'schedule_week_id');
    }

    public function editor()
    {
        return $this->belongsTo(User::class, 'edited_by');
    }
}