<?php

namespace App\Services\Scheduling;

use App\Models\Employee;
use App\Models\EmployeeSkill;
use App\Models\Skill;
use Illuminate\Support\Collection;

/**
 * Skills and ratings in the shape the dashboard expects.
 *
 * The employee's birth date never leaves here: only `is_minor` (null when
 * hiring has none) and, per skill, whether they are under its minimum age
 * (`restricted`). `alert` is that, for a skill they are actually rated on.
 */
class SkillPresenter
{
    public function skill(Skill $skill): array
    {
        return [
            'id' => (string) $skill->id,
            'name' => $skill->name,
            'min_age' => $skill->min_age,
            'restriction_note' => $skill->restriction_note,
        ];
    }

    /**
     * One row per skill, rated or not.
     *
     * @param  Collection<int, Skill>  $skills
     */
    public function employeeSkills(Employee $employee, Collection $skills): array
    {
        $ratings = $employee->skills->keyBy('skill_id');

        return [
            'employee' => $this->employee($employee),
            'skills' => $skills->map(
                fn (Skill $skill) => $this->rating($employee, $skill, $ratings->get($skill->id))
            )->values()->all(),
        ];
    }

    public function rating(Employee $employee, Skill $skill, ?EmployeeSkill $rating): array
    {
        $restricted = $skill->isRestrictedFor($employee);

        return [
            'skill' => $this->skill($skill),
            'rating' => $rating?->rating,
            'notes' => $rating?->notes,
            'updated_at' => $rating?->updated_at?->toIso8601String(),
            'restricted' => $restricted,
            'alert' => $restricted && $rating !== null,
        ];
    }

    /**
     * `store_numbers` is each employee's stores among the ones asked for (all
     * of them when none were), so the client knows where it may rate them.
     *
     * @param  Collection<int, Employee>  $employees  with `skills` and `stores` loaded
     * @param  Collection<int, Skill>  $skills
     * @param  array<int, string>  $storeNumbers
     */
    public function overview(Collection $employees, Collection $skills, array $storeNumbers = []): array
    {
        $skillsById = $skills->keyBy('id');

        return [
            'skills' => $skills->map(fn (Skill $skill) => $this->skill($skill))->values()->all(),
            'employees' => $employees->map(function (Employee $employee) use ($skills, $skillsById, $storeNumbers) {
                $ratings = [];
                $alerts = [];

                foreach ($employee->skills as $row) {
                    $skill = $skillsById->get($row->skill_id);
                    if ($skill === null) {
                        continue;
                    }

                    $ratings[(string) $skill->id] = ['rating' => $row->rating, 'notes' => $row->notes];

                    if ($skill->isRestrictedFor($employee)) {
                        $alerts[] = (string) $skill->id;
                    }
                }

                $stores = $employee->stores->pluck('store_number')
                    ->when($storeNumbers !== [], fn ($all) => $all->intersect($storeNumbers))
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                return $this->employee($employee) + [
                    'store_numbers' => $stores,
                    // An object even when empty, so the client can index it.
                    'ratings' => (object) $ratings,
                    // Under the skill's minimum age, rated or not: the warning
                    // shown before rating them on it.
                    'restricted_skill_ids' => $skills
                        ->filter(fn (Skill $skill) => $skill->isRestrictedFor($employee))
                        ->map(fn (Skill $skill) => (string) $skill->id)
                        ->values()
                        ->all(),
                    'alert_skill_ids' => $alerts,
                ];
            })->values()->all(),
        ];
    }

    private function employee(Employee $employee): array
    {
        return [
            'id' => (string) $employee->id,
            'name' => trim("{$employee->first_name} {$employee->last_name}"),
            'is_active' => (bool) $employee->active,
            'is_minor' => $employee->isMinor(),
        ];
    }
}
