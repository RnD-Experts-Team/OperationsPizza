<?php

namespace App\Services\Api;

use App\Models\Employee;
use App\Models\Skill;
use Illuminate\Support\Collection;
class SkillService
{
    public function getAll(): Collection
    {
        return Employee::with('skills')
            ->get()
            ->map(function ($employee) {
                return [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'skills' => $employee->skills->map(function ($skill) {
                        return [
                            'id' => $skill->id,
                            'name' => $skill->name,
                            'rating' => $skill->pivot->rating,
                        ];
                    }),
                ];
            });
    }
    public function getById(int $id): Skill
    {
        return Skill::findOrFail($id);
    }

    public function create(array $data): Skill
    {
        return Skill::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);
    }

    public function update(Skill $skill, array $data): Skill
    {
        $skill->update([
            'name' => $data['name'] ?? $skill->name,
            'description' => array_key_exists('description', $data)
                ? $data['description']
                : $skill->description,
        ]);

        return $skill->fresh();
    }

    public function delete(Skill $skill): bool
    {
        return $skill->delete();
    }

    public function restore(Skill $skill): bool
    {
        return $skill->restore();
    }
     public function getTrashed(): Collection
    {
        return Skill::onlyTrashed()
            ->orderBy('id', 'asc')
            ->get();
    }

    public function getByIdWithTrashed(int $id): Skill
    {
        return Skill::withTrashed()->findOrFail($id);
    }
}