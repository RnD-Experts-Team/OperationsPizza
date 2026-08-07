<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Our position -> Humanity position, per store. A null position_id row is the store default. */
class HumanityPositionMap extends Model
{
    protected $table = 'humanity_position_map';

    protected $fillable = ['store_id', 'position_id', 'humanity_position_id', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }
}
