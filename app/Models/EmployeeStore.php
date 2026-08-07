<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Store membership as hiring reports it. Matched on store_number because the
 * hiring payload identifies stores that way, and the store may not have
 * replicated from pizzasys yet — store_id is backfilled when it does.
 */
class EmployeeStore extends Model
{
    protected $table = 'employee_store';

    protected $fillable = [
        'employee_id', 'store_number', 'store_id', 'status', 'active', 'effective_date',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'effective_date' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
