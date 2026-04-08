<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoadTemplateRequest;
use App\Http\Requests\SaveTemplateRequest;
use App\Services\Api\ScheduleTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Throwable;
use Illuminate\Http\Request;

class ScheduleTemplateController extends Controller
{
    public function __construct(private ScheduleTemplateService $service) {}

    public function AllTemplate(Request $request, int $store): JsonResponse
    {
        try {
            $perPage = min((int) $request->get('per_page', 10), 50);

            $data = $this->service->getAllPaginated($perPage, $store);

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

    public function saveTemplate(SaveTemplateRequest $request, int $store): JsonResponse
    {
        try {
            $payload = array_merge($request->validated(), [
                'store_id' => $store
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

    public function loadTemplate(LoadTemplateRequest $request, int $store): JsonResponse
    {
        try {
            $payload = array_merge($request->validated(), [
                'store_id' => $store
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

    public function showTemplate(int $store, int $id): JsonResponse
    {
        try {
            $template = $this->service->getById($id, $store);

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

    public function DeleteTemplate(int $store, int $id): JsonResponse
    {
        try {
            $template = $this->service->getById($id, $store);

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

    public function forceDelete(int $store, int $id): JsonResponse
    {
        try {
            $this->service->forceDelete($id, $store);

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

    public function restore(int $store, int $id): JsonResponse
    {
        try {
            $this->service->restore($id, $store);

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