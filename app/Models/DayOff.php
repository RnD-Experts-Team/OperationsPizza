<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DayOff extends Model
{
    protected $table = 'days_off';

    protected $fillable = [
        'employee_id',
        'date',
        'requested_at',
        'type',
        'note',
        'acceptedStatus'
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}