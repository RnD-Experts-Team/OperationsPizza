<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class OperationsOutboxEvent extends Model
{
    use HasUlids;

    protected $table = 'operations_outbox_events';

    protected $fillable = [
        'subject',
        'type',
        'payload',
        'attempts',
        'last_error',
        'published_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'published_at' => 'datetime',
    ];
}
