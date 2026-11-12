<?php

return [
    /*
     |--------------------------------------------------------------------------
     | Store timezones
     |--------------------------------------------------------------------------
     | pizzasys store events carry no timezone (its stores table has no such
     | column). The preferred source is the store's `metadata.timezone` in
     | pizzasys; this map is the second-choice override, keyed by store_number.
     |
     | Every shift is stored as a UTC instant derived from the store's local
     | wall-clock time, so a wrong value here corrupts an entire schedule
     | silently. ReplicatesStores logs a warning whenever it falls through to
     | the default.
     */
    'default_timezone' => env('OPERATIONS_DEFAULT_TIMEZONE', 'America/Chicago'),

    'store_timezones' => [
        // '03759-00001' => 'America/New_York',
    ],

    /*
     | Dashboard positions can carry a variant suffix that neither Humanity's
     | positions nor TCP's job codes have: "Crew Member - AT" should schedule
     | and clock in as the store's "Crew Member". When the full label matches
     | nothing, the lookups retry with one of these trailing " - <suffix>"
     | parts removed (App\Support\PositionLabel). Comma-separated,
     | case-insensitive. Same env variable as HiringPizza, so one setting drives
     | both: the Humanity shift lookup, TCP clock-ins, and HiringPizza's TCP push.
     */
    'position_variant_suffixes' => array_values(array_filter(array_map('trim',
        explode(',', (string) env('TCP_POSITION_VARIANT_SUFFIXES', 'AT,Management Trainee'))))),
];
