<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Store ↔ TCP Location binding, written only by tcp:sync-catalog.
 *
 * The convention is name equality (location name == store_number); this row
 * pins the TCP id so a rename or a convention slip is detected rather than
 * silently changing which location a store means.
 */
class TcpLocation extends Model
{
    protected $fillable = [
        'store_id', 'tcp_location_id', 'name', 'last_synced_at',
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
