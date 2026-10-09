<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One punched segment, mirrored from TCP exactly as TCP holds it.
 *
 * Nothing here is ours to decide. TCP owns every time, duration and punch on
 * this row absolutely, and no local write may override one — the only fields
 * this service supplies are `origin` (which TCP cannot tell us afterwards) and
 * `actual_shift_id` (the grouping, which TCP has no concept of).
 *
 * A row with `time_out` NULL is somebody on the clock RIGHT NOW. That is the
 * entire live-state mechanism: there is no separate table for it, because an
 * open row already is that fact — and because the previous separate table could
 * only ever be written by our own API, so a punch made at a physical clock or in
 * TCP's own app never reached it.
 *
 * Not to be confused with App\Services\Tcp\Dto\TcpWorkSegment, which is the wire
 * shape returned by the vendor client. This is the stored row.
 */
class TcpWorkSegment extends Model
{
    use SoftDeletes;

    /** Our clock endpoints created it. */
    public const ORIGIN_PUNCH = 'punch';
    /** A manager hand-entered it through the review grid. */
    public const ORIGIN_MANUAL = 'manual';
    /** The sync found it already in TCP — a physical clock, or TCP's own UI. */
    public const ORIGIN_DISCOVERED = 'discovered';

    protected $fillable = [
        'tcp_work_segment_id', 'actual_shift_id', 'employee_id', 'store_id',
        'tcp_employee_id', 'tcp_job_code_id', 'origin',
        'time_in', 'time_out', 'starts_at_utc', 'ends_at_utc', 'duration_minutes',
        'actual_punch_in_at', 'actual_punch_out_at',
        'missed_in_punch', 'missed_out_punch', 'segment_note',
        'tcp_updated_on', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'time_in' => 'datetime',
            'time_out' => 'datetime',
            'starts_at_utc' => 'datetime',
            'ends_at_utc' => 'datetime',
            'duration_minutes' => 'integer',
            'actual_punch_in_at' => 'datetime',
            'actual_punch_out_at' => 'datetime',
            'missed_in_punch' => 'boolean',
            'missed_out_punch' => 'boolean',
            'tcp_updated_on' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function actualShift(): BelongsTo
    {
        return $this->belongsTo(ActualShift::class, 'actual_shift_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** On the clock: punched in, not yet out. */
    public function isOpen(): bool
    {
        return $this->time_out === null;
    }

    /** Who is on the clock. The whole of "clocked in", as one indexed read. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('time_out');
    }

    public function scopeForStore(Builder $query, int $storeId): Builder
    {
        return $query->where('store_id', $storeId);
    }

    /**
     * Segments overlapping a window, by UTC instant.
     *
     * Never by the wall-clock columns: these shifts routinely cross midnight,
     * and that is the house rule everywhere else in this service. An open
     * segment has no end, so it counts as overlapping anything that starts
     * after it began.
     */
    public function scopeOverlapping(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $query
            ->where('starts_at_utc', '<', $to)
            ->where(fn (Builder $q) => $q->whereNull('ends_at_utc')->orWhere('ends_at_utc', '>', $from));
    }

    /**
     * TCP flags a segment it knows is incomplete. A missed punch means the
     * recorded time is a system default, not something the employee did — so it
     * has to reach a manager rather than be counted as fact.
     */
    public function hasMissedPunch(): bool
    {
        return $this->missed_in_punch || $this->missed_out_punch;
    }

    /**
     * Did a person actually punch this, or did a manager type it in?
     *
     * This is what replaces `actual_shifts.source`, and it is the one thing
     * about a segment TCP cannot answer later — TCP stores a segment
     * identically however it was made.
     */
    public function isFromClock(): bool
    {
        return $this->origin !== self::ORIGIN_MANUAL;
    }
}
