<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Local mirror of TCP's job-code catalog, written only by tcp:sync-catalog.
 *
 * `store_number` comes from the "Restaurant Id" custom field: NULL means a
 * company-wide code (Regular, Sick, ...), a value means a per-store code like
 * "Crew Member - 3795-01". TcpJobCodeResolver matches an employee's position
 * label against `description` within their store's codes.
 */
class TcpJobCode extends Model
{
    protected $fillable = [
        'tcp_job_code_id', 'description', 'store_number',
        'clockable', 'is_active', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'clockable' => 'boolean',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }
}
