<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSyncRequest extends Model
{
    protected $fillable = [
        'employee_id', 'status', 'requested_by_user_id', 'requested_at',
        'fulfilled_at', 'attempts', 'last_error',
    ];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'fulfilled_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
