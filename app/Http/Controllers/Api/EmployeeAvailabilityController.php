<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeAvailabilityRequest;
use App\Http\Requests\UpdateEmployeeAvailabilityRequest;
use App\Services\Api\EmployeeAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

class EmployeeAvailabilityController extends Controller
{
    public function __construct(
        protected EmployeeAvailabilityService $service
    ) {}

    public function index(int $store): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->service->getAll($store)
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(StoreEmployeeAvailabilityRequest $request, int $store): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->service->create($request->validated(), $store)
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(int $store, int $employee_availability): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $this->service->getById($employee_availability, $store)
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

    public function update(UpdateEmployeeAvailabilityRequest $request, int $store, int $employee_availability): JsonResponse
    {
        try {
            $availability = $this->service->getById($employee_availability, $store);

            return response()->json([
                'success' => true,
                'data' => $this->service->update($availability, $request->validated(), $store)
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

    public function destroy(int $store, int $employee_availability): JsonResponse
    {
        try {
            $availability = $this->service->getById($employee_availability, $store);

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