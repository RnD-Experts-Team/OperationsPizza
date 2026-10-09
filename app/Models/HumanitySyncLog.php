<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class HumanitySyncLog extends Model
{
    use HasUlids;

    protected $table = 'humanity_sync_log';

    protected $fillable = [
        'store_id', 'entity_type', 'entity_id', 'humanity_id', 'operation',
        'status', 'diff', 'error_message',
    ];

    protected $casts = [
        'diff' => 'array',
    ];
}
