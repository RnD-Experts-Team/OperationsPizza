<?php

namespace App\Services\Api;

use App\Models\DayOff;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class DayOffService
{
    

     public function create(array $data)
    {
        return DB::transaction(function () use ($data) {

            $results = [];

            foreach ($data['requests'] as $item) {
                $results[] = DayOff::create([
                    'employee_id' => $item['employee_id'],
                    'date' => $item['date'],
                    'type' => $item['type'],
                    'note' => $item['note'],

                    'requested_at' => now(),
                    'acceptedStatus' => 'pending',
                    'accepted_by' => null,
                ]);
            }

            return $results;
        });
    }

    public function getAll(int $storeId): Collection
    {
        return DayOff::with(['employee', 'acceptedBy'])
            ->whereHas('employee', function ($q) use ($storeId) {
                $q->where('store_id', $storeId);
            })
            ->orderBy('employee_id', 'asc')
            ->orderBy('date', 'asc')
            ->get();
    }

    public function getById(int $id, int $storeId): DayOff
    {
        return DayOff::with(['employee', 'acceptedBy'])
            ->where('id', $id)
            ->whereHas('employee', function ($q) use ($storeId) {
                $q->where('store_id', $storeId);
            })
            ->firstOrFail();
    }

    public function update(DayOff $dayOff, array $data, ?int $userId): DayOff
    {
        $updateData = [];

        if (array_key_exists('date', $data)) {
            $updateData['date'] = $data['date'];
        }

        if (array_key_exists('managerNote', $data)) {
            $updateData['managerNote'] = $data['managerNote'];
        }

        if (array_key_exists('acceptedStatus', $data)) {
            $updateData['acceptedStatus'] = $data['acceptedStatus'];
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