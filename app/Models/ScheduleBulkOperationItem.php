<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleBulkOperationItem extends Model
{
    protected $fillable = [
        'bulk_operation_id', 'sequence', 'action', 'status', 'employee_id',
        'shift_id', 'payload', 'error_code', 'error_message', 'attempts',
    ];

    protected $casts = ['payload' => 'array'];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(ScheduleBulkOperation::class, 'bulk_operation_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
