<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class HumanityDeadLetter extends Model
{
    use HasUlids;

    protected $fillable = [
        'humanity_sync_log_id', 'store_id', 'entity_type', 'entity_id',
        'operation', 'payload', 'error_code', 'error_message', 'attempts',
        'parked_at', 'resolved_at', 'resolved_by_user_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'parked_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];
}
