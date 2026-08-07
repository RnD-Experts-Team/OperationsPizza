<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ScheduleTemplate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'store_id', 'name', 'description', 'shift_count', 'total_minutes', 'created_by_user_id',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(ScheduleTemplateShift::class);
    }
}
