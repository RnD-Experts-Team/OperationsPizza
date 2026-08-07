<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HumanityLocation extends Model
{
    protected $fillable = [
        'store_id', 'humanity_location_id', 'name', 'timezone', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return ['last_synced_at' => 'datetime'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
