<?php

namespace App\Services\Api;

use App\Models\Availability;
use Illuminate\Database\Eloquent\Collection;

class AvailabilityService
{
    public function getAll(): Collection
    {
        return Availability::with('employee')
            ->orderBy('employee_id', 'asc')
            ->orderByRaw("
                CASE day_of_week
                    WHEN 'monday' THEN 1
                    WHEN 'tuesday' THEN 2
                    WHEN 'wednesday' THEN 3
                    WHEN 'thursday' THEN 4
                    WHEN 'friday' THEN 5
                    WHEN 'saturday' THEN 6
                    WHEN 'sunday' THEN 7
                END
            ")
            ->get();
    }

    public function getById(int $id): Availability
    {
        return Availability::with('employee')->findOrFail($id);
    }

    public function create(array $data): Availability
    {
        return Availability::create($data);
    }

    public function update(Availability $availability, array $data): Availability
    {
        $availability->update($data);

        return $availability->fresh();
    }

    public function delete(Availability $availability): bool
    {
        return $availability->delete();
    }
}