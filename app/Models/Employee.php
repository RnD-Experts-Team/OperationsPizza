<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $fillable = [
        'store_id','name','phone','email','hire_date','status'
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function skills()
    {
        return $this->belongsToMany(Skill::class, 'employee_skills')
            ->withPivot('rating');
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }

    public function daysOff()
    {
        return $this->hasMany(DayOff::class);
    }

    public function availability()
    {
        return $this->hasMany(Availability::class);
    }
}
