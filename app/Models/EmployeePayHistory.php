<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeePayHistory extends Model
{
    protected $fillable = ['employee_id', 'base_pay', 'performance_pay', 'effective_date'];

    protected function casts(): array
    {
        return [
            'base_pay' => 'decimal:4',
            'performance_pay' => 'decimal:4',
            'effective_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
