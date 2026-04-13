<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
class Store extends Model
{
    protected $fillable = ['storeNumber','id'];

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

 
    public function getRouteKeyName(): string
    {
        return 'store';  
    }
    public function masterSchedules()
    {
        return $this->hasMany(MasterSchedule::class);
    }
    public function scheduleTemplateStores()
    {
        return $this->hasMany(ScheduleTemplateStore::class, 'store_id');
    }
}