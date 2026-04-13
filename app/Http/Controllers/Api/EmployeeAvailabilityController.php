<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeAvailabilityRequest;
use App\Http\Requests\UpdateEmployeeAvailabilityRequest;
use App\Models\Store;
use App\Services\Api\EmployeeAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

class EmployeeAvailabilityController extends Controller
{
    public function __construct(
        protected EmployeeAvailabilityService $service
    ) {}

    public function index(Store $store): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->service->getAll($store->id)
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(StoreEmployeeAvailabilityRequest $request, Store $store): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->service->create($request->validated(), $store->id)
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Store $store, int $employee_availability): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->service->getById($employee_availability, $store->id)
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Not found'
            ], 404);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(UpdateEmployeeAvailabilityRequest $request, Store $store, int $employee_availability): JsonResponse
    {
        try {
            $availability = $this->service->getById($employee_availability, $store->id);

            return response()->json([
                'success' => true,
                'data' => $this->service->update($availability, $request->validated(), $store->id)
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Not found'
            ], 404);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Store $store, int $employee_availability): JsonResponse
    {
        try {
            $availability = $this->service->getById($employee_availability, $store->id);

            $this->service->delete($availability);

            return response()->json([
                'success' => true,
                'message' => 'Deleted successfully'
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Not found'
            ], 404);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}