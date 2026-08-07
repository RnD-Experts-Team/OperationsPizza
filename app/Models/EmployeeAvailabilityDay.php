<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeAvailabilityDay extends Model
{
    protected $fillable = ['employee_id', 'day_of_week', 'shift_type'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function times(): HasMany
    {
        return $this->hasMany(EmployeeAvailabilityTime::class, 'employee_availability_day_id');
    }
}
