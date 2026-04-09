<?php

namespace App\Http\Controllers\Api;

use Throwable;
use Illuminate\Http\JsonResponse;
use App\Services\Api\DayOffService;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Http\Requests\StoreDayOffRequest;
use App\Http\Requests\UpdateDayOffRequest;
use App\Models\Store;

class DayOffController extends Controller
{
    protected DayOffService $service;

    public function __construct(DayOffService $service)
    {
        $this->service = $service;
    }
 
    public function store(StoreDayOffRequest $request): JsonResponse
    {
        try {
            $data = $this->service->create($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Requests submitted successfully',
                'data' => $data
            ], 201);

        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Failed to create request',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function index(Store $store): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->service->getAll($store->id)
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Error fetching data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

   

    public function update(UpdateDayOffRequest $request, Store $store, int $day_off): JsonResponse
    {
        try {
            $record = $this->service->getById($day_off, $store->id);

            $updated = $this->service->update(
                $record,
                $request->validated(),
                auth()->id()
            );

            return response()->json([
                'success' => true,
                'message' => 'Updated successfully',
                'data' => $updated
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Not found'], 404);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Update failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Store $store, int $day_off): JsonResponse
    {
        try {
            $record = $this->service->getById($day_off, $store->id);
            $this->service->delete($record);

            return response()->json([
                'success' => true,
                'message' => 'Deleted successfully'
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Not found'], 404);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Delete failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    

    

    
}