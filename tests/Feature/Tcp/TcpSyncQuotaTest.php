<?php

namespace Tests\Feature\Tcp;

use App\Models\Employee;
use App\Models\EmployeeStore;
use App\Models\HumanityLocation;
use App\Models\Store;
use App\Services\Tcp\FakeTcpClient;
use App\Services\Tcp\TcpWorkSegmentSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The daily quota is 2500 calls for the WHOLE service, so what a routine sync
 * round costs is a correctness concern, not an optimisation.
 *
 * `tcp:sync-worksegments` runs once per store. GET /calculationchanges answers
 * "whose time card changed?" for the entire account, so a round must ask it
 * ONCE — not once per store, and not once per distinct cursor-minute, which is
 * what the per-store cursor used to produce.
 */
class TcpSyncQuotaTest extends TestCase
{
    use RefreshDatabase;

    private FakeTcpClient $tcp;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tcp.driver' => 'fake', 'tcp.writes_enabled' => true]);

        $this->tcp = app(FakeTcpClient::class);

        // Ten stores, each with one TCP-linked employee.
        foreach (range(1, 10) as $i) {
            $storeNumber = sprintf('0375%d-00001', $i);

            $store = Store::query()->create([
                'id' => $i,
                'store_number' => $storeNumber,
                'name' => "Store {$i}",
            ]);
            $store->settings();

            HumanityLocation::query()->create([
                'store_id' => $i,
                'humanity_location_id' => "LOC{$i}",
                'name' => "Store {$i}",
                'timezone' => 'America/Chicago',
            ]);

            Employee::query()->create([
                'id' => 500 + $i,
                'first_name' => "Emp{$i}",
                'last_name' => 'Test',
                'active' => true,
                'tcp_employee_id' => (string) (500 + $i),
            ]);

            EmployeeStore::query()->create([
                'employee_id' => 500 + $i,
                'store_number' => $storeNumber,
                'store_id' => $i,
                'status' => 'hired',
                'active' => true,
            ]);

            $this->tcp->seedEmployee((string) (500 + $i));
        }
    }

    private function changeFeedCalls(): int
    {
        return count(array_filter(
            $this->tcp->calls,
            fn (array $call) => $call['op'] === 'listCalculationChanges'
        ));
    }

    /**
     * Cursors as a real previous run leaves them: each store stamps its own,
     * seconds apart, because a quiet store finishes in milliseconds.
     *
     * The clock is frozen so the run cannot straddle a bucket boundary by luck
     * — that would make this pass or fail depending on the wall clock.
     */
    private function primeCursors(int $spreadSeconds): void
    {
        $this->travelTo('2026-09-08 12:00:00');

        $stores = Store::all();
        $step = $stores->count() > 1 ? $spreadSeconds / ($stores->count() - 1) : 0;

        foreach ($stores->values() as $i => $store) {
            Cache::put(
                'tcp:worksegments:cursor:' . $store->id,
                now()->subMinutes(35)->addSeconds((int) round($i * $step))->toIso8601String(),
                3600
            );
        }

        $this->tcp->calls = [];
    }

    public function test_a_quiet_round_across_ten_stores_costs_one_change_feed_call(): void
    {
        $sync = app(TcpWorkSegmentSync::class);

        // A quiet round: every store finishes fast, so the cursors land inside
        // the same minute.
        $this->primeCursors(spreadSeconds: 45);

        foreach (Store::all() as $store) {
            $sync->sync($store);
        }

        $this->assertSame(
            1,
            $this->changeFeedCalls(),
            'ten stores in one round must share a single account-wide change-feed call'
        );
    }

    public function test_a_slow_round_still_shares_the_change_feed(): void
    {
        $sync = app(TcpWorkSegmentSync::class);

        // A busy round, where stores fetching segments push later stores into
        // the following minutes. The bucket is what keeps this at one call
        // instead of one per minute spanned.
        $this->primeCursors(spreadSeconds: 200);

        foreach (Store::all() as $store) {
            $sync->sync($store);
        }

        $this->assertLessThanOrEqual(
            2,
            $this->changeFeedCalls(),
            'a round spanning several minutes must still not cost a call per minute'
        );
    }

    public function test_nothing_changed_means_no_segment_fetch_at_all(): void
    {
        $sync = app(TcpWorkSegmentSync::class);

        $this->primeCursors(spreadSeconds: 45);

        foreach (Store::all() as $store) {
            $sync->sync($store);
        }

        $segmentFetches = count(array_filter(
            $this->tcp->calls,
            fn (array $call) => $call['op'] === 'listWorkSegments'
        ));

        $this->assertSame(0, $segmentFetches, 'a quiet round must not page anyone segments');
    }
}
