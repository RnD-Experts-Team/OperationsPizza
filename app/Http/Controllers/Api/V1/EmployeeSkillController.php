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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;
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

    /**
     * Every active employee of the given stores against every skill.
     *
     * Store-less like HiringPizza's all-stores employee list: `storeIds[]`
     * (store codes) narrows it, and pizzasys checks the caller at each of them.
     * None sent is every store, which only a global holder gets past pizzasys.
     */
    public function overview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'storeIds' => ['sometimes', 'array', 'max:100'],
            'storeIds.*' => ['string', 'max:20'],
        ]);
        $storeNumbers = array_values(array_unique($validated['storeIds'] ?? []));

        $employees = Employee::query()
            ->schedulable()
            ->when($storeNumbers !== [], fn ($query) => $query->whereHas(
                'stores',
                fn ($q) => $q->whereIn('store_number', $storeNumbers)
            ))
            ->with(['skills', 'stores'])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        return response()->json([
            'data' => $this->presenter->overview($employees, Skill::query()->orderBy('id')->get(), $storeNumbers),
        ]);
    }

    /**
     * The skill ratings as CSV for Excel: one row per active employee, store
     * they belong to and skill they are rated on, with their birth date.
     *
     * Behind the shared secret (auth.secret.key), not a user token, so there is
     * no caller to check against stores: `stores` (store codes, comma-separated
     * or stores[]) narrows it, and none is every store. `start_date` and
     * `end_date` (inclusive, UTC) keep the ratings last saved in that span,
     * either may be left out. Skills an employee is not rated on have no row.
     */
    public function export(Request $request): StreamedResponse
    {
        $rules = [
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'stores' => ['nullable'],
        ];
        if ($request->filled('start_date')) {
            $rules['end_date'][] = 'after_or_equal:start_date';
        }

        // Excel asks without Accept: application/json, so a failed validate() would redirect to "/".
        $validator = Validator::make($request->query(), $rules);
        abort_if($validator->fails(), 422, $validator->errors()->first());
        $filters = $validator->validated();

        $stores = $filters['stores'] ?? [];
        $storeNumbers = array_values(array_unique(array_filter(array_map(
            'trim',
            is_array($stores) ? $stores : explode(',', (string) $stores)
        ))));

        $rows = DB::table('employee_skills')
            ->join('skills', 'skills.id', '=', 'employee_skills.skill_id')
            ->join('employees', 'employees.id', '=', 'employee_skills.employee_id')
            ->join('employee_store', 'employee_store.employee_id', '=', 'employees.id')
            ->where('employees.active', true)
            ->whereNull('employees.deleted_at')
            ->when($storeNumbers !== [], fn ($query) => $query->whereIn('employee_store.store_number', $storeNumbers))
            ->when($filters['start_date'] ?? null, fn ($query, $date) => $query->whereDate('employee_skills.updated_at', '>=', $date))
            ->when($filters['end_date'] ?? null, fn ($query, $date) => $query->whereDate('employee_skills.updated_at', '<=', $date))
            ->orderBy('employee_store.store_number')
            ->orderBy('employees.first_name')
            ->orderBy('employees.last_name')
            ->orderBy('employees.id')
            ->orderBy('skills.id')
            ->select([
                'employee_store.store_number',
                'employees.id as employee_id',
                'employees.first_name',
                'employees.last_name',
                'employees.birth_date',
                'skills.name as skill',
                'employee_skills.rating',
                'employee_skills.updated_at as rated_at',
            ]);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Store', 'Employee ID', 'Employee', 'Birth date', 'Skill', 'Rating', 'Rated on'], escape: '');
            foreach ($rows->cursor() as $row) {
                fputcsv($out, [
                    $row->store_number,
                    $row->employee_id,
                    trim("{$row->first_name} {$row->last_name}"),
                    $row->birth_date === null ? '' : substr((string) $row->birth_date, 0, 10),
                    $row->skill,
                    $row->rating,
                    substr((string) $row->rated_at, 0, 10),
                ], escape: '');
            }
            fclose($out);
        }, 'employee-skills_'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
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
