<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMasterScheduleRequest;
use App\Http\Requests\UpdateMasterScheduleRequest;
use App\Services\Api\MasterScheduleService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;
use App\Http\Requests\InitSchedulingRequest;
use App\Http\Requests\CopyPreviousWeekRequest;
use Illuminate\Support\Facades\Log;

class MasterScheduleController extends Controller
{
    public function __construct(private MasterScheduleService $service) {}

    public function getPublishedSchedules(int $store): JsonResponse
    {
        try {
            $data = $this->service->getPublishedSchedules($store);

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch published schedules.'
            ], 500);
        }
    }

    public function index(Request $request, int $store): JsonResponse
    {
        try {
            $perPage = min((int) $request->get('per_page', 10), 50);

            $data = $this->service->getAllPaginated($perPage, $store);

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Failed to fetch data',
            ], 500);
        }
    }

    public function store(StoreMasterScheduleRequest $request, int $store): JsonResponse
    {
        try {
            $payload = array_merge($request->validated(), [
                'store_id' => $store
            ]);

            $record = $this->service->storeWithSchedules($payload, auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'Created successfully',
                'data' => $record,
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Creation failed',
            ], 500);
        }
    }

    public function show(int $store, int $id): JsonResponse
    {
        try {
            $record = $this->service->getById($id, $store);

            return response()->json([
                'success' => true,
                'data' => $record,
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Not found',
            ], 404);
        }
    }

    public function update(UpdateMasterScheduleRequest $request, int $store, int $id): JsonResponse
    {
        try {
            $record = $this->service->getById($id, $store);

            $payload = array_merge($request->validated(), [
                'store_id' => $store
            ]);

            $updated = $this->service->updateWithSchedules(
                $record,
                $payload,
                auth()->id()
            );

            return response()->json([
                'success' => true,
                'message' => 'Updated successfully',
                'data' => $updated,
            ]);

        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Not found',
            ], 404);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Update failed',
            ], 500);
        }
    }

    public function publish(int $store, int $id): JsonResponse
    {
        try {
            $record = $this->service->getById($id, $store);

            $published = $this->service->publish($record, auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'Published successfully',
                'data' => $published,
            ]);

        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Not found'], 404);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->errors()], 422);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Publish failed'], 500);
        }
    }

    public function unpublish(int $store, int $id): JsonResponse
    {
        try {
            $record = $this->service->getById($id, $store);

            $unpublished = $this->service->unpublish($record);

            return response()->json([
                'success' => true,
                'message' => 'Unpublished successfully',
                'data' => $unpublished,
            ]);

        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Not found'], 404);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->errors()], 422);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Unpublish failed'], 500);
        }
    }

    public function trashed(int $store): JsonResponse
    {
        try {
            $data = $this->service->getTrashed($store);

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);

        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Failed to fetch trashed records',
            ], 500);
        }
    }

    public function softDelete(int $store, int $id): JsonResponse
    {
        try {
            $record = $this->service->getById($id, $store);

            $this->service->delete($record);

            return response()->json([
                'success' => true,
                'message' => 'Deleted successfully',
            ]);

        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Not found'], 404);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Delete failed'], 500);
        }
    }

    public function restore(int $store, int $id): JsonResponse
    {
        try {
            $record = $this->service->restore($id, $store);

            return response()->json([
                'success' => true,
                'message' => 'Restored successfully',
                'data' => $record,
            ]);

        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Not found'], 404);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Restore failed'], 500);
        }
    }

    public function forceDelete(int $store, int $id): JsonResponse
    {
        try {
            $this->service->forceDelete($id, $store);

            return response()->json([
                'success' => true,
                'message' => 'Permanently deleted successfully',
            ]);

        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Not found'], 404);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Force delete failed'], 500);
        }
    }

    public function deleteSchedule(int $store, int $id): JsonResponse
    {
        try {
            $this->service->deleteSchedule($id, $store);

            return response()->json([
                'success' => true,
                'message' => 'Schedule deleted successfully',
            ]);

        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Schedule not found'], 404);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->errors()], 422);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Delete failed'], 500);
        }
    }

    public function restoreSchedule(int $store, int $id): JsonResponse
    {
        try {
            $record = $this->service->restoreSchedule($id,$store);

            return response()->json([
                'success' => true,
                'message' => 'Schedule restored successfully',
                'data' => $record,
            ]);

        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Schedule not found'], 404);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Restore failed'], 500);
        }
    }

    public function forceDeleteSchedule(int $store, int $id): JsonResponse
    {
        try {
            $this->service->forceDeleteSchedule($id,$store);

            return response()->json([
                'success' => true,
                'message' => 'Schedule permanently deleted successfully',
            ]);

        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Schedule not found'], 404);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->errors()], 422);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Force delete failed'], 500);
        }
    }

    public function initScheduling(InitSchedulingRequest $request, int $store): JsonResponse
    {
        try {
            $data = $this->service->initScheduling(
                array_merge($request->validated(), [
                    'store_id' => $store
                ])
            );

            return response()->json([
                'success' => true,
                'message' => 'Scheduling initialized successfully',
                'data' => $data,
            ]);

        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to initialize scheduling',
            ], 500);
        }
    }

    public function copyWeek(CopyPreviousWeekRequest $request, int $store): JsonResponse
    {
        try {
            $data = $this->service->copySchedule(
                array_merge($request->validated(), [
                    'store_id' => $store
                ]),
                auth()->id()
            );

            return response()->json([
                'success' => true,
                'message' => 'Schedule copied successfully',
                'data' => $data
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->errors()
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Failed to copy schedule'
            ], 500);
        }
    }
}