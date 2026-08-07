<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class PublishedSchedule extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'store_id', 'week_start_date', 'week_label', 'screenshot_disk',
        'screenshot_path', 'screenshot_bytes', 'shift_snapshot', 'shift_count',
        'total_minutes', 'published_by_user_id', 'published_at', 'unpublished_at',
    ];

    protected function casts(): array
    {
        return [
            'week_start_date' => 'date',
            'shift_snapshot' => 'array',
            'published_at' => 'datetime',
            'unpublished_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function screenshotUrl(): ?string
    {
        if (!$this->screenshot_path) {
            return null;
        }

        return Storage::disk($this->screenshot_disk ?: 'public')->url($this->screenshot_path);
    }
}
