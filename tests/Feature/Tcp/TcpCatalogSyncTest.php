<?php

namespace Tests\Feature\Tcp;

use App\Models\Store;
use App\Models\TcpJobCode;
use App\Models\TcpLocation;
use App\Services\Tcp\FakeTcpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * tcp:sync-catalog is where the "TCP location name == store_number" convention
 * is enforced — the binding it writes is what clocking and employee pushes
 * rely on, so this is the command that has to be paranoid.
 */
class TcpCatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeTcpClient $tcp;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tcp.driver' => 'fake']);

        Store::query()->create(['id' => 1, 'store_number' => '03795-00001', 'name' => 'Downtown']);
        Store::query()->create(['id' => 2, 'store_number' => '03795-00002', 'name' => 'Uptown']);

        $this->tcp = app(FakeTcpClient::class);
    }

    public function test_locations_bind_to_stores_by_name_and_job_codes_by_custom_field(): void
    {
        // Real shapes from the account: location named by store_number,
        // job codes attributed by the Restaurant Id custom field.
        $this->tcp->seedLocation('9830400', '03795-00001');
        $this->tcp->seedLocation('9830401', '03795-00002');
        $this->tcp->seedJobCode('37950101', 'Crew Member - 3795-01', '03795-00001');
        $this->tcp->seedJobCode('1000', 'Regular'); // company-wide, no store

        $this->artisan('tcp:sync-catalog')->assertSuccessful();

        $this->assertSame('9830400', TcpLocation::query()->where('store_id', 1)->value('tcp_location_id'));
        $this->assertSame('9830401', TcpLocation::query()->where('store_id', 2)->value('tcp_location_id'));

        $perStore = TcpJobCode::query()->where('tcp_job_code_id', '37950101')->sole();
        $this->assertSame('03795-00001', $perStore->store_number);
        $this->assertTrue($perStore->clockable);

        $global = TcpJobCode::query()->where('tcp_job_code_id', '1000')->sole();
        $this->assertNull($global->store_number);
    }

    public function test_a_store_without_a_matching_location_fails_the_command(): void
    {
        $this->tcp->seedLocation('9830400', '03795-00001');
        // Store 2 has no TCP location — the runbook gates on this exit code.

        $this->artisan('tcp:sync-catalog')->assertFailed();

        $this->assertNull(TcpLocation::query()->where('store_id', 2)->first());
    }

    public function test_a_rebound_location_id_is_a_conflict_not_a_silent_repoint(): void
    {
        TcpLocation::query()->create([
            'store_id' => 1,
            'tcp_location_id' => 'OLD-ID',
            'name' => '03795-00001',
        ]);

        // TCP now serves a different id under the same name (location was
        // deleted and recreated, or the convention broke).
        $this->tcp->seedLocation('9830400', '03795-00001');
        $this->tcp->seedLocation('9830401', '03795-00002');

        $this->artisan('tcp:sync-catalog');

        // The binding must NOT move — that would change what every punch and
        // shift for this store means.
        $this->assertSame('OLD-ID', TcpLocation::query()->where('store_id', 1)->value('tcp_location_id'));
    }

    public function test_check_mode_writes_nothing(): void
    {
        $this->tcp->seedLocation('9830400', '03795-00001');
        $this->tcp->seedLocation('9830401', '03795-00002');
        $this->tcp->seedJobCode('1000', 'Regular');

        $this->artisan('tcp:sync-catalog --check')->assertSuccessful();

        $this->assertSame(0, TcpLocation::query()->count());
        $this->assertSame(0, TcpJobCode::query()->count());
    }
}
