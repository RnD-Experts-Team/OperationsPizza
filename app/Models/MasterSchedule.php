<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MasterSchedule extends Model
{
    use SoftDeletes;
    protected $table = 'master_schedule';

    protected $fillable = [
        'store_id','start_date','end_date','published','created_by','published_by'
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
    public function publisher()
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class, 'schedule_week_id');
    }
}