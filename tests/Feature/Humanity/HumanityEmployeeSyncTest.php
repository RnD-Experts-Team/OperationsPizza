<?php

namespace Tests\Feature\Humanity;

use App\Models\Employee;
use App\Services\Humanity\FakeHumanityClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * humanity:sync-employees — the READ-ONLY replacement for the deleted
 * employee-push loop. TCP's connector carries employees into Humanity; this
 * matches what it created back to our replicas by TCP id.
 */
class HumanityEmployeeSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeHumanityClient $humanity;

    protected function setUp(): void
    {
        parent::setUp();

        config(['humanity.driver' => 'fake']);

        $this->humanity = app(FakeHumanityClient::class);
    }

    private function employee(int $id, ?string $tcpId): Employee
    {
        return Employee::query()->create([
            'id' => $id,
            'first_name' => 'E',
            'last_name' => (string) $id,
            'active' => true,
            'tcp_employee_id' => $tcpId,
        ]);
    }

    public function test_matches_by_eid(): void
    {
        $this->employee(501, '5896');
        $this->humanity->seedEmployee('88213', ['eid' => '5896', 'username' => 'irrelevant']);

        $this->artisan('humanity:sync-employees')->assertSuccessful();

        $this->assertSame('88213', Employee::find(501)->humanity_employee_id);
    }

    public function test_matches_by_username_prefix_when_eid_is_empty(): void
    {
        // The observed real-account shape: username = "{tcpId}.{companyCode}".
        $this->employee(502, '5897');
        $this->humanity->seedEmployee('88214', ['eid' => null, 'username' => '5897.lce03795']);

        $this->artisan('humanity:sync-employees')->assertSuccessful();

        $this->assertSame('88214', Employee::find(502)->humanity_employee_id);
    }

    public function test_an_unmatchable_record_links_nobody(): void
    {
        $this->employee(503, '5898');
        $this->humanity->seedEmployee('88215', ['eid' => '9999', 'username' => 'jane.doe']);

        $this->artisan('humanity:sync-employees')->assertSuccessful();

        $this->assertNull(Employee::find(503)->humanity_employee_id);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->employee(504, '5899');
        $this->humanity->seedEmployee('88216', ['eid' => '5899']);

        $this->artisan('humanity:sync-employees --dry-run')->assertSuccessful();

        $this->assertNull(Employee::find(504)->humanity_employee_id);
    }
}
