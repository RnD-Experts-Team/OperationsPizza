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

    public function AllTemplate(): JsonResponse
    {
        try {
            $data = $this->service->getAll();

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
    public function saveTemplate(SaveTemplateRequest $request): JsonResponse
    {
        try {
            $template = $this->service->saveTemplate(
                $request->validated(),
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
  

     

    public function loadTemplate(LoadTemplateRequest $request): JsonResponse
    {
        try {
            $data = $this->service->loadTemplatePreview($request->validated());

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
    public function showTemplate(int $id): JsonResponse
    {
        try {
            $template = $this->service->getById($id);

            return response()->json([
                'success' => true,
                'message' => 'Template fetched successfully',
                'data' => $template,
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found',
            ], 404);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch template',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function DeleteTemplate(int $id): JsonResponse
    {
        try {
            $template = $this->service->getById($id);

            $this->service->delete($template);

            return response()->json([
                'success' => true,
                'message' => 'Template deleted successfully',
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found',
            ], 404);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete template',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}