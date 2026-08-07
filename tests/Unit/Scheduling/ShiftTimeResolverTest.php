<?php

namespace Tests\Unit\Scheduling;

use App\Services\Scheduling\Exceptions\InvalidLocalTimeException;
use App\Services\Scheduling\ShiftTimeResolver;
use PHPUnit\Framework\TestCase;

class ShiftTimeResolverTest extends TestCase
{
    private ShiftTimeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ShiftTimeResolver();
    }

    public function test_a_normal_shift_converts_to_utc(): void
    {
        // 2026-08-04 is CDT (UTC-5).
        $time = $this->resolver->resolve('2026-08-04', '09:00', '17:00', 'America/Chicago');

        $this->assertSame('2026-08-04', $time->shiftDate);
        $this->assertSame('09:00:00', $time->startTime);
        $this->assertSame('17:00:00', $time->endTime);
        $this->assertSame('2026-08-04 14:00:00', $time->startsAtUtc->toDateTimeString());
        $this->assertSame('2026-08-04 22:00:00', $time->endsAtUtc->toDateTimeString());
        $this->assertSame(480, $time->durationMinutes);
        $this->assertFalse($time->crossesMidnight);
    }

    public function test_a_shift_ending_at_midnight_is_a_full_day_not_zero_length(): void
    {
        // The store closes at 00:00, so this is the ordinary closing shift.
        $time = $this->resolver->resolve('2026-08-04', '17:00', '00:00', 'America/Chicago');

        $this->assertSame(420, $time->durationMinutes);
        $this->assertTrue($time->crossesMidnight);
        $this->assertSame('2026-08-05 05:00:00', $time->endsAtUtc->toDateTimeString());
    }

    public function test_an_overnight_shift_lands_on_the_next_day(): void
    {
        $time = $this->resolver->resolve('2026-08-04', '22:00', '02:00', 'America/Chicago');

        $this->assertSame(240, $time->durationMinutes);
        $this->assertTrue($time->crossesMidnight);
        // shift_date stays the START date; the end rolls over.
        $this->assertSame('2026-08-04', $time->shiftDate);
        $this->assertSame('2026-08-05 07:00:00', $time->endsAtUtc->toDateTimeString());
    }

    public function test_a_nonexistent_spring_forward_time_is_rejected(): void
    {
        // 2026-03-08 02:00-03:00 does not exist in America/Chicago.
        $this->expectException(InvalidLocalTimeException::class);

        $this->resolver->resolve('2026-03-08', '02:30', '06:00', 'America/Chicago');
    }

    public function test_a_fall_back_overnight_shift_is_nine_hours_not_eight(): void
    {
        // 2026-11-01 falls back at 02:00, so 22:00 Oct 31 -> 06:00 Nov 1 spans
        // an extra real hour. This is the payroll number, which is exactly why
        // hours must never be recomputed from the wall-clock strings.
        $time = $this->resolver->resolve('2026-10-31', '22:00', '06:00', 'America/Chicago');

        $this->assertSame(540, $time->durationMinutes);
        $this->assertNotSame(480, $time->durationMinutes);
    }

    public function test_duration_is_the_true_utc_delta_across_spring_forward(): void
    {
        // 2026-03-08: 22:00 Mar 7 -> 06:00 Mar 8 loses an hour.
        $time = $this->resolver->resolve('2026-03-07', '22:00', '06:00', 'America/Chicago');

        $this->assertSame(420, $time->durationMinutes);
    }

    public function test_it_accepts_loose_time_formats(): void
    {
        $time = $this->resolver->resolve('2026-08-04', '9:00', '17:00:00', 'America/Chicago');

        $this->assertSame('09:00:00', $time->startTime);
        $this->assertSame(480, $time->durationMinutes);
    }

    public function test_wall_clock_duration_helper(): void
    {
        $this->assertSame(480, $this->resolver->wallClockDurationMinutes('09:00', '17:00'));
        $this->assertSame(240, $this->resolver->wallClockDurationMinutes('22:00', '02:00'));
        $this->assertSame(900, $this->resolver->wallClockDurationMinutes('09:00', '00:00'));
        // A same-time pair means a full 24h, never a zero-length shift.
        $this->assertSame(1440, $this->resolver->wallClockDurationMinutes('09:00', '09:00'));
    }
}
