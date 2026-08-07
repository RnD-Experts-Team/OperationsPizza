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
];
