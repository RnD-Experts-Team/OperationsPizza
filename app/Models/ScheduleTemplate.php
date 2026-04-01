<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
class ScheduleTemplate extends Model
{
    protected $fillable = [
        'name',
        'description',
        'store_id'
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function details()
    {
        return $this->hasMany(ScheduleTemplateDetail::class);
    }
}