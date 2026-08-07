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
        'idempotency_key', 'status', 'http_method', 'endpoint', 'http_status',
        'humanity_status', 'request_payload', 'response_payload', 'diff',
        'attempts', 'error_code', 'error_message', 'duration_ms', 'correlation_id',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'diff' => 'array',
    ];
}
