<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Humanity Position — the container a shift belongs to, and what the
 * scheduling UI renders as "department". Humanity's API calls this the
 * `schedule` parameter; there is no separate schedule entity.
 */
class HumanityPosition extends Model
{
    protected $fillable = [
        'humanity_position_id', 'humanity_location_id', 'name', 'color',
        'is_active', 'remote_updated_at', 'deleted_remotely_at', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'remote_updated_at' => 'datetime',
            'deleted_remotely_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }
}
