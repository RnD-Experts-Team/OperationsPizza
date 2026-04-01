<?php

namespace App\Services\Api;

use App\Models\AvailabilityTime;
use Illuminate\Database\Eloquent\Collection;

class AvailabilityTimeService
{
    public function getAll(): Collection
    {
        return AvailabilityTime::with('availability.employee')
            ->join('availabilities', 'availability_times.availability_id', '=', 'availabilities.id')
            ->orderBy('availabilities.employee_id', 'asc')
            ->orderByRaw("
                CASE availabilities.day_of_week
                    WHEN 'monday' THEN 1
                    WHEN 'tuesday' THEN 2
                    WHEN 'wednesday' THEN 3
                    WHEN 'thursday' THEN 4
                    WHEN 'friday' THEN 5
                    WHEN 'saturday' THEN 6
                    WHEN 'sunday' THEN 7
                END
            ")
            ->orderBy('availability_times.from', 'asc')
            ->select('availability_times.*')
            ->get();
    }

    public function getById(int $id): AvailabilityTime
    {
        return AvailabilityTime::with('availability')->findOrFail($id);
    }

    public function create(array $data): AvailabilityTime
    {
        return AvailabilityTime::create($data);
    }

    public function update(AvailabilityTime $record, array $data): AvailabilityTime
    {
        $record->update($data);
        return $record->fresh();
    }

    public function delete(AvailabilityTime $record): bool
    {
        return $record->delete();
    }
}