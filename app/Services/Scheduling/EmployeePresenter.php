<?php

namespace App\Services\Scheduling;

use App\Models\Employee;
use App\Models\HumanityPosition;
use App\Models\HumanityPositionMap;
use App\Models\Store;
use Illuminate\Support\Collection;

/**
 * Employees in the shape the grid expects.
 *
 * `avatar` and `color` are assigned here rather than by the client: the
 * prototype baked them into its mock data, and deriving them from the employee
 * id keeps a person the same colour across sessions, devices and users.
 */
class EmployeePresenter
{
    /** Same palette the grid already styles against. */
    public const COLORS = [
        'blue', 'emerald', 'violet', 'amber', 'rose',
        'cyan', 'orange', 'pink', 'indigo', 'teal',
    ];

    /**
     * @param  Collection<int, Employee>  $employees
     * @return array<int, array<string, mixed>>
     */
    public function present(Collection $employees, Store $store, LaborCostCalculator $labor, ?string $onDate = null): array
    {
        $departments = $this->departmentsByPosition($store);
        $onDate ??= now()->toDateString();

        return $employees->map(function (Employee $employee) use ($departments, $labor, $onDate) {
            $label = $employee->position_label;

            return [
                'id' => (string) $employee->id,
                'name' => trim("{$employee->first_name} {$employee->last_name}"),
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'role' => $label,
                'department' => $label === null ? null : ($departments[$label] ?? null),
                'avatar' => $this->initials($employee),
                'color' => $this->color($employee),
                'is_active' => (bool) $employee->active,
                'status' => $employee->current_status,
                'humanity_employee_id' => $employee->humanity_employee_id,
                // The UI needs this to decide whether to show a "Syncing…"
                // badge instead of letting a manager schedule the person.
                'synced' => $employee->isLinkedToHumanity(),
                'hourly_rate' => $labor->rateFor((int) $employee->id, $onDate),
            ];
        })->values()->all();
    }

    public function initials(Employee $employee): string
    {
        $first = mb_substr((string) $employee->first_name, 0, 1);
        $last = mb_substr((string) $employee->last_name, 0, 1);

        return mb_strtoupper($first . $last);
    }

    /** Deterministic, so the same person is the same colour everywhere. */
    public function color(Employee $employee): string
    {
        $index = (int) $employee->id % count(self::COLORS);

        return self::COLORS[$index];
    }

    /**
     * Position label → its Humanity position name, which is what the UI calls
     * a department. Unmapped labels simply have none.
     */
    public function departmentsByPosition(Store $store): array
    {
        $map = HumanityPositionMap::query()
            ->where('store_id', $store->id)
            ->whereNotNull('position_label')
            ->pluck('humanity_position_id', 'position_label');

        if ($map->isEmpty()) {
            return [];
        }

        $names = HumanityPosition::query()
            ->whereIn('humanity_position_id', $map->values()->all())
            ->pluck('name', 'humanity_position_id');

        return $map->map(fn ($humanityId) => $names[$humanityId] ?? null)->all();
    }

    /** The department filter list, in the order the store mapped them. */
    public function departments(Store $store): array
    {
        $humanityIds = HumanityPositionMap::query()
            ->where('store_id', $store->id)
            ->pluck('humanity_position_id')
            ->unique();

        return HumanityPosition::query()
            ->whereIn('humanity_position_id', $humanityIds)
            ->orderBy('name')
            ->get()
            ->map(fn (HumanityPosition $position) => [
                'id' => $position->humanity_position_id,
                'name' => $position->name,
                'humanity_position_id' => $position->humanity_position_id,
            ])
            ->values()
            ->all();
    }
}
