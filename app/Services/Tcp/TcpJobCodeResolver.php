<?php

namespace App\Services\Tcp;

use App\Models\Employee;
use App\Models\HumanityPositionMap;
use App\Models\Store;
use App\Services\Scheduling\Exceptions\SchedulingException;
use Illuminate\Support\Facades\Cache;

/**
 * Which TCP job code a punch belongs to.
 *
 * TCP owns job codes, and TCP's connector surfaces them in Humanity as
 * positions — so the store↔position mapping already built for scheduling is
 * describing TCP job codes all along, just seen through Humanity. Rather than
 * introduce a second mapping table for operators to keep in step, this resolves
 * through the existing one and falls back to the store default.
 *
 * A clockIn without a valid job code is rejected by TCP, so an unmapped store
 * fails here with a clear error instead of an opaque 400.
 */
class TcpJobCodeResolver
{
    private const CACHE_KEY = 'tcp:jobcodes';
    private const CACHE_TTL = 3600;

    public function __construct(private readonly TcpClientInterface $tcp)
    {
    }

    public function resolve(Store $store, ?Employee $employee = null, ?int $positionId = null): string
    {
        $candidates = [];

        if ($positionId !== null) {
            $candidates[] = $positionId;
        }

        if ($employee !== null) {
            $positions = $employee->relationLoaded('positions')
                ? $employee->positions
                : $employee->positions()->get();

            foreach ($positions->sortByDesc(fn ($p) => (int) $p->pivot->is_primary) as $position) {
                $candidates[] = (int) $position->id;
            }
        }

        foreach ($candidates as $candidate) {
            $mapped = HumanityPositionMap::query()
                ->where('store_id', $store->id)
                ->where('position_id', $candidate)
                ->value('humanity_position_id');

            if (filled($mapped) && $this->jobCodeExists((string) $mapped)) {
                return (string) $mapped;
            }
        }

        $default = HumanityPositionMap::query()
            ->where('store_id', $store->id)
            ->whereNull('position_id')
            ->value('humanity_position_id');

        if (filled($default) && $this->jobCodeExists((string) $default)) {
            return (string) $default;
        }

        throw new SchedulingException(
            "No TCP job code is mapped for store {$store->store_number}.",
            'JOB_CODE_NOT_MAPPED',
            422,
            [
                'store_number' => (string) $store->store_number,
                'resolution' => 'Map one with: php artisan humanity:map-position --store=' . $store->store_number . ' --default',
            ]
        );
    }

    /**
     * Guards against a mapping that points at a Humanity position with no TCP
     * job code behind it — possible if a position was created directly in
     * Humanity instead of TCP, which the connector does not support.
     *
     * Cached: this runs on every punch, and the daily quota is 2500.
     */
    private function jobCodeExists(string $jobCodeId): bool
    {
        $ids = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $ids = [];

            foreach ($this->tcp->listJobCodes() as $jobCode) {
                $id = $jobCode['jobCodeId'] ?? $jobCode['id'] ?? null;

                if ($id !== null && !is_array($id)) {
                    $ids[] = (string) $id;
                }
            }

            return $ids;
        });

        // An empty catalog means we could not read it, not that nothing exists;
        // failing open here beats blocking every clock-in on a cache miss.
        return $ids === [] || in_array($jobCodeId, $ids, true);
    }

    public function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
