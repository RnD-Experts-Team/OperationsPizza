<?php

namespace Tests\Feature\Humanity;

use App\Services\Humanity\HumanityHttpClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Humanity returns shift dates as objects carrying a Unix timestamp. Reading
 * them in UTC moved any edge after UTC midnight onto the next day, so a
 * 4-8 PM Central shift came back ending 8 PM the following day (28 hours).
 */
class ShiftReadTimezoneTest extends TestCase
{
    private const TZ = 'America/Chicago';

    protected function setUp(): void
    {
        parent::setUp();

        config(['humanity.requests_per_second' => 0]);
        Cache::put('humanity:oauth', ['access_token' => 'test-token'], 3600);
        Http::preventStrayRequests();
    }

    private function fakeShift(string $startLocal, string $endLocal): void
    {
        $starts = CarbonImmutable::parse($startLocal, self::TZ);
        $ends = CarbonImmutable::parse($endLocal, self::TZ);

        Http::fake(['www.humanity.com/api/v2/shifts/*' => Http::response([
            'status' => 1,
            'data' => [
                'id' => 1278547576,
                'schedule' => 55,
                'start_date' => ['timestamp' => $starts->getTimestamp(), 'formatted' => $starts->format('D M j, Y')],
                'end_date' => ['timestamp' => $ends->getTimestamp(), 'formatted' => $ends->format('D M j, Y')],
                'start_time' => $starts->format('H:i'),
                'end_time' => $ends->format('H:i'),
                'employees' => [],
            ],
        ])]);
    }

    public function test_an_evening_shift_ends_on_its_own_day_not_the_next(): void
    {
        $this->fakeShift('2026-11-12 16:00', '2026-11-12 20:00');

        $shift = app(HumanityHttpClient::class)->getShift('1278547576', self::TZ);

        $this->assertSame('2026-11-12', $shift->startDate);
        $this->assertSame('16:00', $shift->startTime);
        $this->assertSame('2026-11-12', $shift->endDate);
        $this->assertSame('20:00', $shift->endTime);
    }

    public function test_a_shift_starting_after_utc_midnight_keeps_its_local_day(): void
    {
        $this->fakeShift('2026-11-12 19:00', '2026-11-12 23:00');

        $shift = app(HumanityHttpClient::class)->getShift('1278547576', self::TZ);

        $this->assertSame('2026-11-12', $shift->startDate);
        $this->assertSame('2026-11-12', $shift->endDate);
    }

    public function test_a_shift_that_really_crosses_midnight_still_ends_the_next_day(): void
    {
        $this->fakeShift('2026-11-12 20:00', '2026-11-13 02:00');

        $shift = app(HumanityHttpClient::class)->getShift('1278547576', self::TZ);

        $this->assertSame('2026-11-12', $shift->startDate);
        $this->assertSame('2026-11-13', $shift->endDate);
        $this->assertSame('02:00', $shift->endTime);
    }
}
