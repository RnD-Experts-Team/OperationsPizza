<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Humanity shift can hold several employees, so assignment is its own row.
 * The API flattens this back out: GET /schedule/week returns one item per
 * assignment, which is what the one-card-per-employee grid expects.
 */
class ShiftAssignment extends Model
{
    use SoftDeletes;

    protected $fillable = ['shift_id', 'employee_id', 'humanity_employee_id', 'status'];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function actualShift(): HasOne
    {
        return $this->hasOne(ActualShift::class);
    }
}
