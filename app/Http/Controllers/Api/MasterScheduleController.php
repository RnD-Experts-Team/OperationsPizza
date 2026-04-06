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
 
class MasterScheduleController extends Controller
{
    public function __construct(private MasterScheduleService $service) {}
    

    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = min((int) $request->get('per_page', 10), 50);

            $data = $this->service->getAllPaginated($perPage);

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Failed to fetch data',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(StoreMasterScheduleRequest $request): JsonResponse
    {
        try {
            $record = $this->service->storeWithSchedules(
                $request->validated(),
                auth()->id()
            );

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
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $record = $this->service->getById($id);

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

    public function update(UpdateMasterScheduleRequest $request, int $id): JsonResponse
    {
        try {
            $record = $this->service->getById($id);

            $updated = $this->service->updateWithSchedules(
                $record,
                $request->validated(),
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
                'error' => $e->getMessage(),
            ], 500);
        }
    }




    public function publish(int $id): JsonResponse
    {
        try {
            $record = $this->service->getById($id);

            $published = $this->service->publish($record,auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'Published successfully',
                'data' => $published,
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
                'message' => 'Publish failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    public function unpublish(int $id): JsonResponse
    {
        try {
            $record = $this->service->getById($id);

            $unpublished = $this->service->unpublish($record);

            return response()->json([
                'success' => true,
                'message' => 'Unpublished successfully',
                'data' => $unpublished,
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
                'message' => 'Unpublish failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    public function trashed(): JsonResponse
    {
        try {
            $data = $this->service->getTrashed();

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);

        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Failed to fetch trashed records',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

  

    public function softDelete(int $id): JsonResponse
    {
        try {
            $record = $this->service->getById($id);

            $this->service->delete($record);

            return response()->json([
                'success' => true,
                'message' => 'Deleted successfully',
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Not found',
            ], 404);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Delete failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    public function restore(int $id): JsonResponse
    {
        try {
            $record = $this->service->restore($id);

            return response()->json([
                'success' => true,
                'message' => 'Restored successfully',
                'data' => $record,
            ]);

        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Not found',
            ], 404);

        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Restore failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    public function forceDelete(int $id): JsonResponse
    {
        try {
            $this->service->forceDelete($id);

            return response()->json([
                'success' => true,
                'message' => 'Permanently deleted successfully',
            ]);

        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Not found',
            ], 404);

        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Force delete failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    public function deleteSchedule(int $id): JsonResponse
    {
        try {
            $this->service->deleteSchedule($id);

            return response()->json([
                'success' => true,
                'message' => 'Schedule deleted successfully',
            ]);

        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Schedule not found',
            ], 404);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->errors(),
            ], 422);

        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Delete failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    public function restoreSchedule(int $id): JsonResponse
    {
        try {
            $record = $this->service->restoreSchedule($id);

            return response()->json([
                'success' => true,
                'message' => 'Schedule restored successfully',
                'data' => $record,
            ]);

        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Schedule not found',
            ], 404);

        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Restore failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    public function forceDeleteSchedule(int $id): JsonResponse
    {
        try {
            $this->service->forceDeleteSchedule($id);

            return response()->json([
                'success' => true,
                'message' => 'Schedule permanently deleted successfully',
            ]);

        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Schedule not found',
            ], 404);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->errors(),
            ], 422);

        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Force delete failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    public function initScheduling(InitSchedulingRequest $request): JsonResponse
    {
        try {
            $data = $this->service->initScheduling($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Scheduling initialized successfully',
                'data' => $data,
            ], 200);

        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to initialize scheduling',
                'error' => $e->getMessage(),
            ], 500);
        }
    }



    public function copyWeek(CopyPreviousWeekRequest $request): JsonResponse
    {
        try {
            $data = $this->service->copySchedule(
                $request->validated(),
                auth()->id()
            );

            return response()->json([
                'success' => true,
                'message' => 'Schedule copied successfully',
                'data' => $data
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {

            return response()->json([
                'message' => $e->errors()
            ], 422);

        } catch (\Throwable $e) {

            return response()->json([
                'message' => 'Failed to copy schedule',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}