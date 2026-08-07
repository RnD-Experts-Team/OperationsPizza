<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScheduleBulkOperation extends Model
{
    use HasUlids;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'store_id', 'type', 'status', 'week_start_date', 'source_week_start_date',
        'schedule_template_id', 'total_items', 'succeeded_items', 'failed_items',
        'params', 'error', 'requested_by_user_id', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'params' => 'array',
        'week_start_date' => 'date',
        'source_week_start_date' => 'date',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ScheduleBulkOperationItem::class, 'bulk_operation_id');
    }

    public function progressPercent(): int
    {
        $total = (int) $this->total_items;

        if ($total === 0) {
            return 100;
        }

        $done = (int) $this->succeeded_items + (int) $this->failed_items;

        return (int) floor($done / $total * 100);
    }
}
