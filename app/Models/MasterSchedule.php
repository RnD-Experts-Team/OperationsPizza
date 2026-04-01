<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterSchedule extends Model
{
    protected $table = 'master_schedule';

    protected $fillable = [
        'store_id','start_date','end_date','published','created_by'
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class, 'schedule_week_id');
    }
}