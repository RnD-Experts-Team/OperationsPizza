<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Skill;
use App\Services\Scheduling\SkillPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The skills list. Not per store: every store rates against the same skills,
 * and only the head (a global `manage skills`) changes it.
 */
class SkillController extends Controller
{
    public function __construct(private readonly SkillPresenter $presenter)
    {
    }

    public function index(): JsonResponse
    {
        $skills = Skill::query()->orderBy('id')->get();

        return response()->json(['data' => $skills->map(fn (Skill $skill) => $this->presenter->skill($skill))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $skill = Skill::query()->create($request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:skills,name'],
            'min_age' => ['nullable', 'integer', 'min:1', 'max:99'],
            'restriction_note' => ['nullable', 'string', 'max:2000'],
        ]));

        return response()->json(['data' => $this->presenter->skill($skill)], 201);
    }

    /** POST, not PUT — house convention. */
    public function update(Request $request, int $skillId): JsonResponse
    {
        $skill = $this->findSkill($skillId);

        $skill->update($request->validate([
            'name' => ['sometimes', 'string', 'max:120', Rule::unique('skills', 'name')->ignore($skill->id)],
            'min_age' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:99'],
            'restriction_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]));

        return response()->json(['data' => $this->presenter->skill($skill->fresh())]);
    }

    /** Takes every employee's rating on it along (cascade). */
    public function destroy(int $skillId): JsonResponse
    {
        $this->findSkill($skillId)->delete();

        return response()->json(null, 204);
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
