<?php

namespace App\Models;

use App\Models\Concerns\ReplicatedModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Replicated from HiringPizza via hiring.v1.employee.*. Never written here
 * except by the event handlers and the Humanity-link backfill.
 */
class Employee extends Model
{
    use ReplicatedModel, SoftDeletes;

    /** Statuses that make a store membership (and thus the employee) active. */
    public const ACTIVE_STATUSES = ['hired', 'rehired'];

    protected $fillable = [
        'id',
        'first_name',
        'middle_name',
        'last_name',
        'gender',
        'employment_type',
        'birth_date',
        'image_url',
        'active',
        'current_status',
        'current_status_effective_date',
        'humanity_employee_id',
        'humanity_synced_at',
        'hiring_event_at',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'birth_date' => 'date',
            'current_status_effective_date' => 'date',
            'humanity_synced_at' => 'datetime',
            'hiring_event_at' => 'datetime',
        ];
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(EmployeeContact::class);
    }

    public function externalIds(): HasMany
    {
        return $this->hasMany(EmployeeExternalId::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(EmployeeStatusHistory::class);
    }

    public function payHistories(): HasMany
    {
        return $this->hasMany(EmployeePayHistory::class);
    }

    public function availabilityDays(): HasMany
    {
        return $this->hasMany(EmployeeAvailabilityDay::class);
    }

    public function stores(): HasMany
    {
        return $this->hasMany(EmployeeStore::class);
    }

    public function positions(): BelongsToMany
    {
        return $this->belongsToMany(Position::class, 'employee_position')
            ->withPivot(['is_primary', 'effective_date'])
            ->withTimestamps();
    }

    public function syncRequest(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(EmployeeSyncRequest::class);
    }

    /** Primary email, falling back to any email on file. */
    public function primaryEmail(): ?string
    {
        return $this->contactValue('email');
    }

    public function primaryPhone(): ?string
    {
        return $this->contactValue('phone');
    }

    private function contactValue(string $type): ?string
    {
        $contacts = $this->relationLoaded('contacts')
            ? $this->contacts
            : $this->contacts()->where('type', $type)->get();

        return $contacts
            ->where('type', $type)
            ->sortByDesc('is_primary')
            ->first()?->value;
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
}
