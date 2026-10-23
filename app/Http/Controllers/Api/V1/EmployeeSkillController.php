<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesStore;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeSkill;
use App\Models\Skill;
use App\Services\Scheduling\SkillPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Employees' skill ratings, reached through a store the employee works at.
 * The rating itself is the employee's, not the store's: a manager at any of
 * their stores sees and edits the same one.
 *
 * A minor rated on a skill with a minimum age is saved like any other rating;
 * the response just carries `alert`.
 */
class EmployeeSkillController extends Controller
{
    use ResolvesStore;

    public function __construct(private readonly SkillPresenter $presenter)
    {
    }

    public function index(string $storeId, int $employeeId): JsonResponse
    {
        $store = $this->resolveStore($storeId);
        $employee = $this->findEmployee((string) $store->store_number, $employeeId)->load('skills');

        return response()->json([
            'data' => $this->presenter->employeeSkills($employee, Skill::query()->orderBy('id')->get()),
        ]);
    }

    /** Create or replace the employee's rating on one skill. */
    public function upsert(Request $request, string $storeId, int $employeeId, int $skillId): JsonResponse
    {
        $store = $this->resolveStore($storeId);
        $employee = $this->findEmployee((string) $store->store_number, $employeeId);
        $skill = $this->findSkill($skillId);

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $rating = EmployeeSkill::query()->updateOrCreate(
            ['employee_id' => $employee->id, 'skill_id' => $skill->id],
            ['rating' => $validated['rating'], 'notes' => $validated['notes'] ?? null]
        );

        return response()->json(['data' => $this->presenter->rating($employee, $skill, $rating)]);
    }

    public function destroy(string $storeId, int $employeeId, int $skillId): JsonResponse
    {
        $store = $this->resolveStore($storeId);
        $employee = $this->findEmployee((string) $store->store_number, $employeeId);

        $deleted = EmployeeSkill::query()
            ->where('employee_id', $employee->id)
            ->where('skill_id', $skillId)
            ->delete();

        if ($deleted === 0) {
            throw new NotFoundHttpException("Employee {$employeeId} has no rating on skill {$skillId}.");
        }

        return response()->json(null, 204);
    }

    /** Every active employee of the store against every skill. */
    public function overview(string $storeId): JsonResponse
    {
        $store = $this->resolveStore($storeId);

        $employees = Employee::query()
            ->assignedToStore((string) $store->store_number)
            ->schedulable()
            ->with('skills')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        return response()->json([
            'data' => $this->presenter->overview($employees, Skill::query()->orderBy('id')->get()),
        ]);
    }

    /**
     * pizzasys only checked the caller against the store in the path, so the
     * employee must belong to that store too.
     */
    private function findEmployee(string $storeNumber, int $employeeId): Employee
    {
        $employee = Employee::query()
            ->assignedToStore($storeNumber)
            ->find($employeeId);

        if ($employee === null) {
            throw new NotFoundHttpException("Employee {$employeeId} is not assigned to store {$storeNumber}.");
        }

        return $employee;
    }

    private function findSkill(int $skillId): Skill
    {
        $skill = Skill::query()->find($skillId);

        if ($skill === null) {
            throw new NotFoundHttpException("Skill {$skillId} not found.");
        }

        return $skill;
    }
}
