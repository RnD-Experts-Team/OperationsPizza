<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreScheduleSetting extends Model
{
    protected $fillable = [
        'store_id',
        'week_start_dow',
        'open_time',
        'close_time',
        'slot_minutes',
        'overtime_threshold_minutes',
        'default_labor_rate_cents',
    ];

    protected function casts(): array
    {
        return [
            'week_start_dow' => 'integer',
            'slot_minutes' => 'integer',
            'overtime_threshold_minutes' => 'integer',
            'default_labor_rate_cents' => 'integer',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
