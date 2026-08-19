<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Position label -> Humanity position, per store. A null position_label row is
 * the store default, used when an employee's label matches nothing.
 *
 * Keyed on the label TEXT rather than a position id because this service does
 * not replicate HiringPizza's position catalog — the label is all a shift
 * write needs, and Humanity names its own positions by the same convention.
 */
class HumanityPositionMap extends Model
{
    protected $table = 'humanity_position_map';

    protected $fillable = ['store_id', 'position_label', 'humanity_position_id', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
