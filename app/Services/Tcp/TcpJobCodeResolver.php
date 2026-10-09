<?php

namespace App\Services\Tcp;

use App\Models\Employee;
use App\Models\Store;
use App\Models\TcpJobCode;
use App\Services\Scheduling\Exceptions\SchedulingException;

/**
 * Which TCP job code a punch belongs to.
 *
 * TCP's catalog is per-store: "Crew Member - 3795-01" attributed to a store by
 * its "Restaurant Id" custom field (mirrored into tcp_job_codes by
 * tcp:sync-catalog). Our position labels are store-agnostic ("Crew Member"),
 * so the match is: same store, description starts with the position label,
 * case-insensitive.
 *
 * Matching on the label TEXT is exactly why this service does not replicate
 * HiringPizza's position catalog — the employee carries their label as a
 * string and that is all this needs. Reads the local mirror only; the catalog
 * is refreshed by tcp:sync-catalog, never fetched per punch.
 *
 * This deliberately does NOT go through the Humanity position mapping — a
 * Humanity position id is Humanity's own key and shares nothing with TCP's
 * jobCodeId, so resolving through it sent garbage codes with every punch.
 *
 * A clockIn without a valid job code is rejected by TCP, so an unmapped store
 * fails here with a clear error instead of an opaque 400.
 */
class TcpJobCodeResolver
{
    public function resolve(Store $store, ?Employee $employee = null, ?string $label = null): string
    {
        $labels = array_values(array_unique(array_filter([
            $label,
            $employee?->position_label,
        ], fn ($value) => filled($value))));

        $storeCodes = TcpJobCode::query()
            ->where('store_number', (string) $store->store_number)
            ->where('clockable', true)
            ->where('is_active', true)
            ->get();

        foreach ($labels as $candidate) {
            $match = $storeCodes->first(
                fn (TcpJobCode $code) => stripos($code->description, $candidate) === 0
            );

            if ($match !== null) {
                return (string) $match->tcp_job_code_id;
            }
        }

        // Optional account-wide fallback (e.g. 1000 "Regular"). Validated
        // against the synced catalog so a typo cannot send an unknown code.
        $fallback = (string) (config('tcp.default_job_code') ?? '');

        if ($fallback !== ''
            && TcpJobCode::query()->where('tcp_job_code_id', $fallback)->where('is_active', true)->exists()
        ) {
            return $fallback;
        }

        $catalogEmpty = TcpJobCode::query()->count() === 0;

        throw new SchedulingException(
            "No TCP job code matches store {$store->store_number}"
                . ($labels === [] ? ' (employee has no position)' : ' for position(s): ' . implode(', ', array_unique($labels)))
                . '.',
            'JOB_CODE_NOT_MAPPED',
            422,
            [
                'store_number' => (string) $store->store_number,
                'position_labels' => array_values(array_unique($labels)),
                'resolution' => $catalogEmpty
                    ? 'The TCP catalog has never been synced: php artisan tcp:sync-catalog'
                    : 'Check tcp:sync-catalog output — the store needs a clockable code whose description starts with the position label.',
            ]
        );
    }
}
