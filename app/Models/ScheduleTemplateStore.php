<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleTemplateStore extends Model
{
    protected $table = 'schedule_template_stores';

    protected $fillable = [
        'schedule_template_id',
        'store_id',
    ];

    public function template()
    {
        return $this->belongsTo(ScheduleTemplate::class, 'schedule_template_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}