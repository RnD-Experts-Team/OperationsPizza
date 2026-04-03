<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
class ScheduleTemplate extends Model
{
    protected $fillable = [
        'name',
        'description',
        'created_by',
    ];

    // public function store()
    // {
    //     return $this->belongsTo(Store::class);
    // }

    public function details()
    {
        return $this->hasMany(ScheduleTemplateDetail::class);
    }
    public function stores()
    {
        return $this->hasMany(ScheduleTemplateStore::class, 'schedule_template_id');
    }
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}