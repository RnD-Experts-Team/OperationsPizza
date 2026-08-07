<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesStore;
use App\Http\Controllers\Controller;
use App\Models\PublishedSchedule;
use App\Services\Scheduling\PublishedScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PublishedScheduleController extends Controller
{
    use ResolvesStore;

    public function __construct(private readonly PublishedScheduleService $published)
    {
    }

    public function index(Request $request, string $storeId): JsonResponse
    {
        $store = $this->resolveStore($storeId);

        $paginator = PublishedSchedule::query()
            ->where('store_id', $store->id)
            ->orderByDesc('published_at')
            ->paginate(min((int) $request->integer('per_page', 25), 100))
            ->withQueryString();

        $paginator->through(fn (PublishedSchedule $row) => $this->published->present($row));

        return response()->json($paginator);
    }

    public function show(string $storeId, int $publishedId): JsonResponse
    {
        $store = $this->resolveStore($storeId);

        return response()->json([
            'data' => $this->published->present($this->find((int) $store->id, $publishedId), withSnapshot: true),
        ]);
    }

    /**
     * Multipart, because the screenshot is a 1-3 MB PNG. The frontend must send
     * canvas.toBlob() rather than the data URL its prototype builds.
     */
    public function store(Request $request, string $storeId): JsonResponse
    {
        $store = $this->resolveStore($storeId);

        $validated = $request->validate([
            'week_start' => ['required', 'date_format:Y-m-d'],
            'screenshot' => ['nullable', 'file', 'image', 'max:8192'],
        ]);

        $published = $this->published->publish(
            $store,
            $validated['week_start'],
            $request->file('screenshot'),
            $request->user()?->id
        );

        return response()->json(['data' => $this->published->present($published)], 201);
    }

    public function destroy(string $storeId, int $publishedId): JsonResponse
    {
        $store = $this->resolveStore($storeId);

        $this->published->delete($this->find((int) $store->id, $publishedId));

        return response()->json(null, 204);
    }

    private function find(int $storeId, int $publishedId): PublishedSchedule
    {
        $published = PublishedSchedule::query()->where('store_id', $storeId)->find($publishedId);

        if ($published === null) {
            throw new NotFoundHttpException("Published schedule {$publishedId} not found.");
        }

        return $published;
    }
}
