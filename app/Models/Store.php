<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
class Store extends Model
{
    protected $fillable = ['storeNumber'];

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

    public function scheduleTemplates()
    {
        return $this->hasMany(ScheduleTemplate::class);
    }

    public function masterSchedules()
    {
        return $this->hasMany(MasterSchedule::class);
    }
}