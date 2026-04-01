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
        'acceptedStatus',
        'accepted_by'
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
    public function acceptedBy()
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }
}