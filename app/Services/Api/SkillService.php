<?php

namespace App\Services\Api;

use App\Models\Skill;
use Illuminate\Database\Eloquent\Collection;

class SkillService
{
   public function getAll(): Collection
    {
        return Skill::with(['employees' => function ($query) {
            $query->orderBy('store_id', 'asc');
        }])
        ->orderBy('id', 'asc')
        ->get();
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
}