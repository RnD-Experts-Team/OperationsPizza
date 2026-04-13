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
use App\Models\Store;
use Illuminate\Support\Facades\Log;

class MasterScheduleController extends Controller
{
    public function __construct(private MasterScheduleService $service) {}

    public function getPublishedSchedules(Store $store): JsonResponse
    {
        try {
            $data = $this->service->getPublishedSchedules($store->id);

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

    public function index(Request $request, Store $store): JsonResponse
    {
        try {
            $perPage = min((int) $request->get('per_page', 10), 50);

            $data = $this->service->getAllPaginated($perPage, $store->id);

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

    public function store(StoreMasterScheduleRequest $request, Store $store): JsonResponse
    {
        try {
            $payload = array_merge($request->validated(), [
                'store_id' => $store->id
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

    public function show(Store $store, int $id): JsonResponse
    {
        try {
            $record = $this->service->getById($id, $store->id);

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

    public function update(UpdateMasterScheduleRequest $request, Store $store, int $id): JsonResponse
    {
        try {
            $record = $this->service->getById($id, $store->id);

            $payload = array_merge($request->validated(), [
                'store_id' => $store->id
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

    public function publish(Store $store, int $id): JsonResponse
    {
        try {
            $record = $this->service->getById($id, $store->id);

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

    public function unpublish(Store $store, int $id): JsonResponse
    {
        try {
            $record = $this->service->getById($id, $store->id);

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

    public function trashed(Store $store): JsonResponse
    {
        try {
            $data = $this->service->getTrashed($store->id);

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

    public function softDelete(Store $store, int $id): JsonResponse
    {
        try {
            $record = $this->service->getById($id, $store->id);

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

    public function restore(Store $store, int $id): JsonResponse
    {
        try {
            $record = $this->service->restore($id, $store->id);

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

    public function forceDelete(Store $store, int $id): JsonResponse
    {
        try {
            $this->service->forceDelete($id, $store->id);

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

    public function deleteSchedule(Store $store, int $id): JsonResponse
    {
        try {
            $this->service->deleteSchedule($id, $store->id);

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

    public function restoreSchedule(Store $store, int $id): JsonResponse
    {
        try {
            $record = $this->service->restoreSchedule($id,$store->id);

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

    public function forceDeleteSchedule(Store $store, int $id): JsonResponse
    {
        try {
            $this->service->forceDeleteSchedule($id,$store->id);

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

    public function initScheduling(InitSchedulingRequest $request, Store $store): JsonResponse
    {
        try {
            $data = $this->service->initScheduling(
                array_merge($request->validated(), [
                    'store_id' => $store->id
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

    public function copyWeek(CopyPreviousWeekRequest $request, Store $store): JsonResponse
    {
        try {
            $data = $this->service->copySchedule(
                array_merge($request->validated(), [
                    'store_id' => $store->id
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