<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAvailabilityTime extends Model
{
    protected $fillable = ['employee_availability_day_id', 'available_from', 'available_to'];

    public function day(): BelongsTo
    {
        return $this->belongsTo(EmployeeAvailabilityDay::class, 'employee_availability_day_id');
    }
}
