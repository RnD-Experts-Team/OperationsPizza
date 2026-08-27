<?php

namespace Tests\Feature\Humanity;

use App\Jobs\SyncEmployeeToHumanityJob;
use App\Models\Employee;
use App\Services\Humanity\FakeHumanityClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The proactive half of Humanity employee linking: fired when a TCP id
 * arrives, rather than waiting for someone to schedule the employee or for the
 * nightly humanity:sync-employees backstop.
 */
class EmployeeLinkJobTest extends TestCase
{
    use RefreshDatabase;

    private FakeHumanityClient $humanity;

    protected function setUp(): void
    {
        parent::setUp();

        config(['humanity.driver' => 'fake']);
        $this->humanity = app(FakeHumanityClient::class);
    }

    private function employee(array $attributes = []): Employee
    {
        return Employee::query()->create(array_merge([
            'id' => 501,
            'first_name' => 'Marco',
            'last_name' => 'Rossi',
            'active' => true,
            'tcp_employee_id' => '9004321',
        ], $attributes));
    }

    public function test_it_links_an_employee_once_the_connector_has_carried_them_across(): void
    {
        $employee = $this->employee();

        // TCP's connector sets the new Humanity record's eid to the TCP id —
        // note Humanity's OWN id is a different value, and it's the one we need.
        $this->humanity->seedEmployee('H77', ['eid' => '9004321']);

        app(SyncEmployeeToHumanityJob::class, ['employeeId' => 501])
            ->handle(app(\App\Services\Humanity\HumanityEmployeeLinker::class));

        $this->assertSame('H77', $employee->fresh()->humanity_employee_id);
        $this->assertNotNull($employee->fresh()->humanity_synced_at);
    }

    public function test_a_miss_is_not_an_error_because_the_connector_may_not_have_run_yet(): void
    {
        $employee = $this->employee();

        // Nothing seeded: Humanity does not know this employee yet.
        app(SyncEmployeeToHumanityJob::class, ['employeeId' => 501])
            ->handle(app(\App\Services\Humanity\HumanityEmployeeLinker::class));

        // Left unlinked rather than half-written, for the shift-write lookup
        // and the nightly backstop to pick up later.
        $this->assertNull($employee->fresh()->humanity_employee_id);
    }

    public function test_an_already_linked_employee_costs_no_humanity_call(): void
    {
        $this->employee(['humanity_employee_id' => 'H77', 'humanity_synced_at' => now()]);

        $before = count($this->humanity->calls);

        app(SyncEmployeeToHumanityJob::class, ['employeeId' => 501])
            ->handle(app(\App\Services\Humanity\HumanityEmployeeLinker::class));

        $this->assertCount($before, $this->humanity->calls);
    }
}
