<?php

namespace App\Services\Scheduling;

use App\Models\PublishedSchedule;
use App\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A published week: the shift list frozen at publish time plus a screenshot.
 *
 * The screenshot is a FILE, not a data URL. html2canvas at scale:2 over a 10x7
 * grid produces 1-3 MB, which has no business travelling in a JSON body or
 * living in a database column.
 */
class PublishedScheduleService
{
    public function __construct(
        private readonly WeekResolver $weeks,
        private readonly ShiftQueryService $shifts,
    ) {
    }

    public function publish(
        Store $store,
        string $weekStartInput,
        ?UploadedFile $screenshot = null,
        ?int $userId = null,
    ): PublishedSchedule {
        $settings = $store->settings();
        $weekStart = $this->weeks->normalizeWeekStart($weekStartInput, $this->weeks->weekStartDow($settings));
        $weekEnd = $weekStart->addDays(6);

        $assignments = $this->shifts->assignmentsForRange($store, $weekStart->toDateString(), $weekEnd->toDateString());
        $snapshot = $this->shifts->toDtos($assignments, $weekStart);

        $path = null;
        $bytes = null;

        if ($screenshot !== null) {
            $path = $screenshot->store("schedules/{$store->store_number}", 'public');
            $bytes = $screenshot->getSize();
        }

        return DB::transaction(function () use ($store, $weekStart, $snapshot, $path, $bytes, $userId) {
            // Re-publishing supersedes the previous record rather than stacking
            // another one, so "the published week" is never ambiguous.
            PublishedSchedule::query()
                ->where('store_id', $store->id)
                ->whereDate('week_start_date', $weekStart->toDateString())
                ->whereNull('unpublished_at')
                ->update(['unpublished_at' => now()]);

            return PublishedSchedule::query()->create([
                'store_id' => $store->id,
                'week_start_date' => $weekStart->toDateString(),
                'week_label' => $this->weeks->label($weekStart),
                'screenshot_disk' => 'public',
                'screenshot_path' => $path,
                'screenshot_bytes' => $bytes,
                'shift_snapshot' => $snapshot,
                'shift_count' => count($snapshot),
                'total_minutes' => array_sum(array_column($snapshot, 'duration_minutes')),
                'published_by_user_id' => $userId,
                'published_at' => now(),
            ]);
        });
    }

    public function unpublish(PublishedSchedule $published): void
    {
        $published->update(['unpublished_at' => now()]);
    }

    public function delete(PublishedSchedule $published): void
    {
        if ($published->screenshot_path) {
            Storage::disk($published->screenshot_disk ?: 'public')->delete($published->screenshot_path);
        }

        $published->delete();
    }

    public function present(PublishedSchedule $published, bool $withSnapshot = false): array
    {
        $data = [
            'id' => (string) $published->id,
            'week_start_date' => $published->week_start_date?->toDateString(),
            'week_label' => $published->week_label,
            'published_at' => $published->published_at?->toIso8601String(),
            'unpublished_at' => $published->unpublished_at?->toIso8601String(),
            // A URL, not a data URL. next/image handles both, so the frontend
            // field name is the only thing that becomes a slight lie.
            'screenshot_url' => $published->screenshotUrl(),
            'shift_count' => (int) $published->shift_count,
            'total_hours' => round((int) $published->total_minutes / 60, 2),
        ];

        if ($withSnapshot) {
            $data['shifts'] = $published->shift_snapshot ?? [];
        }

        return $data;
    }
}
