<?php

use Illuminate\Support\Facades\Schedule;

// Safety net for the transactional outbox: PublishOutboxEventJob is the primary
// path, this sweeps up anything the queue lost or never published.
Schedule::command('outbox:publish-pending')->everyFiveMinutes()->withoutOverlapping();

// Humanity has no webhooks and no `updated_at` on shifts, so polling a rolling
// window is the ONLY way to notice an edit made in Humanity's own UI. This is
// the primary sync mechanism, not a backstop.
//
// Deliberately NOT scheduled against production until Phase 1 is signed off:
// the first live pass imports the existing schedule and can soft-delete local
// rows, which must be a deliberate act (see ReconcileHumanityShiftsCommand).
Schedule::command('humanity:reconcile')
    ->hourly()
    ->withoutOverlapping()
    ->skip(fn () => config('humanity.environment') !== 'sandbox');

// Approved leave is a scheduling guard, so it needs to be fresher than daily.
Schedule::command('humanity:sync-leave')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->skip(fn () => config('humanity.driver') !== 'http');

// Locations and positions are the only Humanity resources with a real delta
// filter, so this is cheap.
Schedule::command('humanity:sync-catalog')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->skip(fn () => config('humanity.driver') !== 'http');
