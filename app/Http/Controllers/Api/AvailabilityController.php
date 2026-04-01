<?php

namespace App\Http\Controllers\Api;

use Throwable;
use App\Models\Availability;
use Illuminate\Http\JsonResponse;
use App\Services\Api\AvailabilityService;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Http\Requests\StoreAvailabilityRequest;
use App\Http\Requests\UpdateAvailabilityRequest;

class AvailabilityController extends Controller
{
    protected AvailabilityService $availabilityService;

    public function __construct(AvailabilityService $availabilityService)
    {
        $this->availabilityService = $availabilityService;
    }

    public function index(): JsonResponse
    {
        try {
            $availabilities = $this->availabilityService->getAll();

            return response()->json([
                'success' => true,
                'message' => 'Availabilities fetched successfully.',
                'data' => $availabilities,
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch availabilities.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(StoreAvailabilityRequest $request): JsonResponse
    {
        try {
            $availability = $this->availabilityService->create($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Availability created successfully.',
                'data' => $availability,
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create availability.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $availability = $this->availabilityService->getById($id);

            return response()->json([
                'success' => true,
                'message' => 'Availability fetched successfully.',
                'data' => $availability,
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Availability not found.',
            ], 404);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch availability.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(UpdateAvailabilityRequest $request, int $id): JsonResponse
    {
        try {
            $availability = $this->availabilityService->getById($id);
            $updatedAvailability = $this->availabilityService->update($availability, $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Availability updated successfully.',
                'data' => $updatedAvailability,
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Availability not found.',
            ], 404);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update availability.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $availability = $this->availabilityService->getById($id);
            $this->availabilityService->delete($availability);

            return response()->json([
                'success' => true,
                'message' => 'Availability deleted successfully.',
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Availability not found.',
            ], 404);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete availability.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}