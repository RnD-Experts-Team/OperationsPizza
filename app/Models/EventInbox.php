<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventInbox extends Model
{
    protected $table = 'event_inbox';

    protected $fillable = [
        'event_id',
        'subject',
        'source',
        'stream',
        'consumer',
        'payload',
        'processed_at',
        'attempts',
        'parked_at',
        'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
        'parked_at' => 'datetime',
    ];
}
