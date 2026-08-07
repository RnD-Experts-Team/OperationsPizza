<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesStore;
use App\Http\Controllers\Controller;
use App\Models\ScheduleTemplate;
use App\Services\Scheduling\ScheduleTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ScheduleTemplateController extends Controller
{
    use ResolvesStore;

    public function __construct(private readonly ScheduleTemplateService $templates)
    {
    }

    public function index(Request $request, string $storeId): JsonResponse
    {
        $store = $this->resolveStore($storeId);

        $paginator = ScheduleTemplate::query()
            ->where('store_id', $store->id)
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->integer('per_page', 25), 100))
            ->withQueryString();

        $paginator->through(fn (ScheduleTemplate $template) => $this->templates->present($template));

        return response()->json($paginator);
    }

    public function show(string $storeId, int $templateId): JsonResponse
    {
        $store = $this->resolveStore($storeId);
        $template = $this->findTemplate((int) $store->id, $templateId)->load('shifts');

        return response()->json(['data' => $this->templates->present($template, withShifts: true)]);
    }

    public function store(Request $request, string $storeId): JsonResponse
    {
        $store = $this->resolveStore($storeId);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:2000'],
            'week_start' => ['required', 'date_format:Y-m-d'],
        ]);

        $template = $this->templates->createFromWeek(
            $store,
            $validated['name'],
            $validated['description'] ?? null,
            $validated['week_start'],
            $request->user()?->id
        );

        return response()->json(['data' => $this->templates->present($template)], 201);
    }

    /** POST, not PUT — house convention. */
    public function update(Request $request, string $storeId, int $templateId): JsonResponse
    {
        $store = $this->resolveStore($storeId);
        $template = $this->findTemplate((int) $store->id, $templateId);

        $template->update($request->validate([
            'name' => ['sometimes', 'string', 'max:190'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]));

        return response()->json(['data' => $this->templates->present($template->fresh())]);
    }

    public function destroy(string $storeId, int $templateId): JsonResponse
    {
        $store = $this->resolveStore($storeId);

        $this->findTemplate((int) $store->id, $templateId)->delete();

        return response()->json(null, 204);
    }

    private function findTemplate(int $storeId, int $templateId): ScheduleTemplate
    {
        $template = ScheduleTemplate::query()->where('store_id', $storeId)->find($templateId);

        if ($template === null) {
            throw new NotFoundHttpException("Template {$templateId} not found.");
        }

        return $template;
    }
}
