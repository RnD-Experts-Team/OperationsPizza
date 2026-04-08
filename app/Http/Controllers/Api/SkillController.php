<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSkillRequest;
 use App\Http\Requests\UpdateSkillRequest;
use App\Models\Skill;
use App\Services\Api\SkillService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Throwable;

class SkillController extends Controller
{
    protected SkillService $skillService;

    public function __construct(SkillService $skillService)
    {
        $this->skillService = $skillService;
    }

    public function index(): JsonResponse
    {
        try {
            $skills = $this->skillService->getAll();

            return response()->json([
                'success' => true,
                'message' => 'Skills fetched successfully.',
                'data' => $skills,
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch skills.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(StoreSkillRequest $request): JsonResponse
    {
        try {
            $skill = $this->skillService->create($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Skill created successfully.',
                'data' => $skill,
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create skill.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
 

    public function update(UpdateSkillRequest $request, int $id): JsonResponse
    {
        try {
            $skill = $this->skillService->getById($id);
            $updatedSkill = $this->skillService->update($skill, $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Skill updated successfully.',
                'data' => $updatedSkill,
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Skill not found.',
            ], 404);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update skill.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $skill = $this->skillService->getById($id);
            $this->skillService->delete($skill);

            return response()->json([
                'success' => true,
                'message' => 'Skill deleted successfully.',
                'code' => 'SKILL_DELETED',
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Skill not found.',
                'code' => 'SKILL_NOT_FOUND',
            ], 404);

        } catch (QueryException $e) {
            if ((int) $e->getCode() === 23000) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete this skill because it is assigned to employees.',
                    'code' => 'SKILL_IN_USE',
                ], 409);
            }

            return response()->json([
                'success' => false,
                'message' => 'Database error occurred while deleting skill.',
                'code' => 'DATABASE_ERROR',
            ], 500);

        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete skill.',
                'code' => 'INTERNAL_SERVER_ERROR',
            ], 500);
        }
    }

    public function restore(int $id): JsonResponse
    {
        try {
            $skill = $this->skillService->getByIdWithTrashed($id);

            if (!$skill->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Skill is not deleted.',
                    'code' => 'SKILL_NOT_TRASHED',
                ], 409);
            }

            $this->skillService->restore($skill);

            return response()->json([
                'success' => true,
                'message' => 'Skill restored successfully.',
                'code' => 'SKILL_RESTORED',
                'data' => $skill->fresh(),
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Skill not found.',
                'code' => 'SKILL_NOT_FOUND',
            ], 404);

        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to restore skill.',
                'code' => 'INTERNAL_SERVER_ERROR',
            ], 500);
        }
    }
    public function trashed(): JsonResponse
    {
        try {
            $skills = $this->skillService->getTrashed();

            return response()->json([
                'success' => true,
                'message' => 'Trashed skills fetched successfully.',
                'data' => $skills,
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch trashed skills.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}