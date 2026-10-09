<?php

namespace App\Models;

use App\Models\Concerns\ReplicatedModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Replicated from HiringPizza via hiring.v1.employee.*. Never written here
 * except by the event handlers and the Humanity-link backfill.
 *
 * Deliberately thin: HiringPizza owns employees, so this carries only what
 * SCHEDULING needs — who they are, whether they are active, where they work,
 * their availability, the two external links, and their position as text. The
 * snapshot still ships pay history, contacts and demographics; we drop them on
 * arrival rather than store an HR record we have no business holding.
 */
class Employee extends Model
{
    use ReplicatedModel, SoftDeletes;

    /** Statuses that make a store membership (and thus the employee) active. */
    public const ACTIVE_STATUSES = ['hired', 'rehired'];

    /**
     * HiringPizza's id_type labels, as they appear in the snapshot's ids[].
     * Kept here (rather than on a model of their own) because we no longer
     * store external ids as rows — they are lifted straight onto this table.
     */
    public const HUMANITY_ID_LABEL = 'Humanity ID';
    public const TCP_ID_LABEL = 'TCP ID';

    protected $fillable = [
        'id',
        'first_name',
        'last_name',
        'active',
        'current_status',
        'hourly_rate',
        'position_label',
        'humanity_employee_id',
        'humanity_synced_at',
        'tcp_employee_id',
        'tcp_synced_at',
        'hiring_event_at',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'hourly_rate' => 'decimal:4',
            'humanity_synced_at' => 'datetime',
            'tcp_synced_at' => 'datetime',
            'hiring_event_at' => 'datetime',
        ];
    }

    public function availabilityDays(): HasMany
    {
        return $this->hasMany(EmployeeAvailabilityDay::class);
    }

    public function stores(): HasMany
    {
        return $this->hasMany(EmployeeStore::class);
    }

    public function syncRequest(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(EmployeeSyncRequest::class);
    }

    public function scopeAssignedToStore(Builder $query, string $storeNumber): Builder
    {
        return $query->whereHas('stores', fn (Builder $q) => $q->where('store_number', $storeNumber));
    }

    public function scopeSchedulable(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function isLinkedToHumanity(): bool
    {
        return $this->humanity_employee_id !== null && $this->humanity_employee_id !== '';
    }

    /** Required before this employee can clock in or have worked hours attributed. */
    public function isLinkedToTcp(): bool
    {
        return $this->tcp_employee_id !== null && $this->tcp_employee_id !== '';
    }
}
