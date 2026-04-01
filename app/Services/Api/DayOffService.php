<?php

namespace App\Services\Api;

use App\Models\DayOff;
use Illuminate\Database\Eloquent\Collection;

class DayOffService
{
    public function getAll(): Collection
    {
        return DayOff::with(['employee', 'acceptedBy'])
            ->orderBy('employee_id', 'asc')
            ->orderBy('date', 'asc')
            ->get();
    }
    public function getById(int $id): DayOff
    {
        return DayOff::with(['employee', 'acceptedBy'])->findOrFail($id);
    }

    public function create(array $data): DayOff
    {
        return DayOff::create([
            'employee_id' => $data['employee_id'],
            'date' => $data['date'],
            'type' => $data['type'],
            'note' => $data['note'],

            'requested_at' => now(),
            'acceptedStatus' => 'pending',
            'accepted_by' => null,
        ]);
    }

    public function update(DayOff $dayOff, array $data, ?int $userId): DayOff
    {
        $updateData = [];

        if (array_key_exists('date', $data)) {
            $updateData['date'] = $data['date'];
        }

        if (array_key_exists('acceptedStatus', $data)) {
            $updateData['acceptedStatus'] = $data['acceptedStatus'];

            // 🔥 أهم سطر
            $updateData['accepted_by'] = $userId;
        }

        $dayOff->update($updateData);

        return $dayOff->fresh();
    }

    public function delete(DayOff $dayOff): bool
    {
        return $dayOff->delete();
    }
}