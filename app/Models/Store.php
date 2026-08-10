<?php

namespace App\Models;

use App\Models\Concerns\ReplicatedModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Replicated from pizzasys via auth.v1.store.*.
 * `store_number` is pizzasys' `store_id` string (e.g. "03759-00001") and is the
 * {storeId} segment in our API routes.
 */
class Store extends Model
{
    use ReplicatedModel, SoftDeletes;

    protected $fillable = [
        'id',
        'store_number',
        'name',
    ];

    /**
     * Identity only. `is_active` lives in pizzasys, and the scheduling timezone
     * comes from the mapped Humanity location — see StoreTimezoneResolver.
     */
    protected function casts(): array
    {
        return [];
    }

    public function scheduleSettings(): HasOne
    {
        return $this->hasOne(StoreScheduleSetting::class);
    }

    /**
     * Settings row, created on demand so callers never have to null-check.
     */
    public function settings(): StoreScheduleSetting
    {
        return $this->scheduleSettings()->firstOrCreate([]);
    }
}
