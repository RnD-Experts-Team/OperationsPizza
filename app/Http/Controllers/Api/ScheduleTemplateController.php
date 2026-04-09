<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoadTemplateRequest;
use App\Http\Requests\SaveTemplateRequest;
use App\Models\Store;
use App\Services\Api\ScheduleTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Throwable;
use Illuminate\Http\Request;

class ScheduleTemplateController extends Controller
{
    public function __construct(private ScheduleTemplateService $service) {}

    public function AllTemplate(Request $request, Store $store): JsonResponse
    {
        try {
            $perPage = min((int) $request->get('per_page', 10), 50);

            $data = $this->service->getAllPaginated($perPage, $store->id);

            return response()->json([
                'success' => true,
                'message' => 'Templates fetched successfully',
                'data' => $data,
            ]);

        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch templates',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function saveTemplate(SaveTemplateRequest $request, Store $store): JsonResponse
    {
        try {
            $payload = array_merge($request->validated(), [
                'store_id' => $store->id
            ]);

            $template = $this->service->saveTemplate(
                $payload,
                auth()->id()
            );

            return response()->json([
                'success' => true,
                'message' => 'Template saved successfully',
                'data' => $template,
            ], 201);

        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Master schedule not found',
            ], 404);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->errors(),
            ], 422);

        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Failed to save template',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function loadTemplate(LoadTemplateRequest $request, Store $store): JsonResponse
    {
        try {
            $payload = array_merge($request->validated(), [
                'store_id' => $store->id
            ]);

            $data = $this->service->loadTemplatePreview($payload);

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);

        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Failed to load template',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function showTemplate(Store $store, int $id): JsonResponse
    {
        try {
            $template = $this->service->getById($id, $store->id);

            return response()->json([
                'success' => true,
                'message' => 'Template fetched successfully',
                'data' => $template,
            ], 200);

        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found',
            ], 404);
        }
    }

    public function DeleteTemplate(Store $store, int $id): JsonResponse
    {
        try {
            $template = $this->service->getById($id, $store->id);

            $this->service->delete($template);

            return response()->json([
                'success' => true,
                'message' => 'Template soft deleted successfully',
            ], 200);

        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found',
            ], 404);
        }
    }

    public function forceDelete(Store $store, int $id): JsonResponse
    {
        try {
            $this->service->forceDelete($id, $store->id);

            return response()->json([
                'success' => true,
                'message' => 'Template permanently deleted',
            ]);

        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found',
            ], 404);
        }
    }

    public function restore(Store $store, int $id): JsonResponse
    {
        try {
            $this->service->restore($id, $store->id);

            return response()->json([
                'success' => true,
                'message' => 'Template restored successfully',
            ]);

        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found',
            ], 404);
        }
    }
}