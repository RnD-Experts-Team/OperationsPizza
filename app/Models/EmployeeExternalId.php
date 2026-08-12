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

    /**
     * TCP Manager+ / TimeClock Plus. TCP is the system of record for employees
     * and the clocking system; its `employeeId` is client-supplied, so for
     * anyone HiringPizza creates this will equal our own employee id. It exists
     * as a stored link because the pre-existing roster carries ids we didn't
     * choose.
     */
    public const TCP = 'TCP ID';

    protected $fillable = ['employee_id', 'id_type', 'value'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
