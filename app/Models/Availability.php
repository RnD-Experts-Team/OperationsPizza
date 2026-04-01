<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Availability extends Model
{
    protected $fillable = ['employee_id','day_of_week'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function times()
    {
        return $this->hasMany(AvailabilityTime::class);
    }
}