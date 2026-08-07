<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HiringPizza's employee_ids + id_types, flattened. The 'Humanity ID' row is
 * the one that matters here; it is lifted onto employees.humanity_employee_id.
 */
class EmployeeExternalId extends Model
{
    public const HUMANITY = 'Humanity ID';

    protected $fillable = ['employee_id', 'id_type', 'value'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
