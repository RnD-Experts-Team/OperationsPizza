<?php

return [
    /*
     |--------------------------------------------------------------------------
     | Store allowlist for external writes
     |--------------------------------------------------------------------------
     | Both vendors are PRODUCTION-ONLY — there is no TCP or Humanity sandbox.
     | This is the rollout guard: while set, every path that writes to TCP or
     | Humanity refuses stores not on the list, so a pilot can run against the
     | dedicated test store while real stores stay untouchable.
     |
     |   EXTERNAL_WRITE_ALLOWED_STORES=03795-99999           # pilot
     |   EXTERNAL_WRITE_ALLOWED_STORES=03795-99999,03795-00001  # widening
     |   (unset)                                             # full production
     |
     | Comma-separated store_numbers. Unset/empty = unrestricted.
     */
    'allowed_stores' => env('EXTERNAL_WRITE_ALLOWED_STORES'),
];
