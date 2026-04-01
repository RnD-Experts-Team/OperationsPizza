<?php

namespace App\Http\Controllers\Api;

use Throwable;
use Illuminate\Http\JsonResponse;
use App\Services\Api\AvailabilityTimeService;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Http\Requests\StoreAvailabilityTimeRequest;
use App\Http\Requests\UpdateAvailabilityTimeRequest;

class AvailabilityTimeController extends Controller
{
    protected AvailabilityTimeService $service;

    public function __construct(AvailabilityTimeService $service)
    {
        $this->service = $service;
    }

    public function index(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->service->getAll()
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(StoreAvailabilityTimeRequest $request): JsonResponse
    {
        try {
            $data = $this->service->create($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Created successfully',
                'data' => $data
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->service->getById($id)
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Not found'], 404);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function update(UpdateAvailabilityTimeRequest $request, int $id): JsonResponse
    {
        try {
            $record = $this->service->getById($id);
            $updated = $this->service->update($record, $request->validated());

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

    public function destroy(int $id): JsonResponse
    {
        try {
            $record = $this->service->getById($id);
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