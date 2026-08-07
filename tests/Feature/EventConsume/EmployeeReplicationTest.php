<?php

namespace Tests\Feature\EventConsume;

use App\Models\Employee;
use App\Models\EmployeeExternalId;
use App\Models\EmployeeSyncRequest;
use App\Models\Store;
use App\Services\EventConsume\Handlers\EmployeeCreatedHandler;
use App\Services\EventConsume\Handlers\EmployeeUpdatedHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeReplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Store::query()->create([
            'id' => 1,
            'store_number' => '03759-00001',
            'name' => 'Downtown',
            'timezone' => 'America/Chicago',
        ]);
    }

    /**
     * Shaped exactly like HiringPizza's snapshot: relations eager-loaded and
     * every *_histories array sorted newest-first.
     */
    private function createdEvent(array $employeeOverrides = []): array
    {
        return [
            'time' => '2026-08-01T12:00:00+00:00',
            'data' => [
                'store_number' => '03759-00001',
                'employee' => array_merge([
                    'id' => 501,
                    'first_name' => 'Marco',
                    'middle_name' => null,
                    'last_name' => 'Rossi',
                    'gender' => 'male',
                    'employment_type' => 'W2',
                    'contacts' => [
                        ['contact_type' => 'email', 'contact_value' => 'marco@example.com', 'is_primary' => true],
                        ['contact_type' => 'phone', 'contact_value' => '555-0100', 'is_primary' => true],
                    ],
                    'status_histories' => [
                        ['id' => 9, 'status' => 'hired', 'effective_date' => '2026-01-15', 'store' => ['store_number' => '03759-00001']],
                    ],
                    'pay_histories' => [
                        ['base_pay' => '16.50', 'performance_pay' => '1.00', 'effective_date' => '2026-01-15'],
                    ],
                    'availability_days' => [
                        [
                            'day_of_week' => 'monday',
                            'shift_type' => 'AM',
                            'times' => [['available_from' => '09:00:00', 'available_to' => '17:00:00']],
                        ],
                    ],
                    'positions' => [
                        ['effective_date' => '2026-01-15', 'position' => ['id' => 4, 'label' => 'Pizzaiolo', 'description' => null]],
                    ],
                    'stores' => [
                        ['effective_date' => '2026-01-15', 'store' => ['id' => 1, 'store_number' => '03759-00001']],
                    ],
                    'ids' => [
                        ['id_value' => 'A-123', 'id_type' => ['label' => 'Altametrics ID']],
                    ],
                    'obsession' => ['birth_date' => '1995-04-02', 'image_url' => 'https://example.test/marco.jpg'],
                ], $employeeOverrides),
            ],
        ];
    }

    public function test_it_replicates_the_full_snapshot(): void
    {
        app(EmployeeCreatedHandler::class)->handle($this->createdEvent());

        $employee = Employee::with(['contacts', 'positions', 'stores', 'availabilityDays.times'])->find(501);

        $this->assertNotNull($employee);
        $this->assertSame(501, $employee->id);
        $this->assertSame('Marco Rossi', $employee->full_name);
        $this->assertSame('marco@example.com', $employee->primaryEmail());
        $this->assertSame('555-0100', $employee->primaryPhone());
        $this->assertSame('1995-04-02', $employee->birth_date->toDateString());
        $this->assertSame('Pizzaiolo', $employee->positions->first()->label);
        $this->assertSame('03759-00001', $employee->stores->first()->store_number);
        // store_id backfilled because the store had already replicated.
        $this->assertSame(1, $employee->stores->first()->store_id);
        $this->assertCount(1, $employee->availabilityDays->first()->times);
    }

    public function test_active_is_derived_from_store_membership_status(): void
    {
        app(EmployeeCreatedHandler::class)->handle($this->createdEvent());

        // The middleware gates employee tokens on this; HiringPizza's copy of
        // that middleware checks a column that doesn't exist there.
        $this->assertTrue(Employee::find(501)->active);
        $this->assertSame('hired', Employee::find(501)->current_status);
    }

    public function test_a_terminated_employee_is_inactive(): void
    {
        app(EmployeeCreatedHandler::class)->handle($this->createdEvent([
            'status_histories' => [
                ['id' => 11, 'status' => 'terminated', 'effective_date' => '2026-06-01', 'store' => ['store_number' => '03759-00001']],
                ['id' => 9, 'status' => 'hired', 'effective_date' => '2026-01-15', 'store' => ['store_number' => '03759-00001']],
            ],
        ]));

        $employee = Employee::find(501);
        $this->assertFalse($employee->active);
        // Latest by effective_date wins, not array order.
        $this->assertSame('terminated', $employee->current_status);
    }

    public function test_it_lifts_the_humanity_id_out_of_external_ids(): void
    {
        app(EmployeeCreatedHandler::class)->handle($this->createdEvent([
            'ids' => [
                ['id_value' => 'A-123', 'id_type' => ['label' => 'Altametrics ID']],
                ['id_value' => '88213', 'id_type' => ['label' => 'Humanity ID']],
            ],
        ]));

        $employee = Employee::find(501);
        $this->assertSame('88213', $employee->humanity_employee_id);
        $this->assertNotNull($employee->humanity_synced_at);
        $this->assertTrue($employee->isLinkedToHumanity());
    }

    public function test_an_employee_without_a_humanity_id_is_not_linked(): void
    {
        app(EmployeeCreatedHandler::class)->handle($this->createdEvent());

        $this->assertNull(Employee::find(501)->humanity_employee_id);
        $this->assertFalse(Employee::find(501)->isLinkedToHumanity());
    }

    public function test_the_updated_delta_leaves_untouched_fields_alone(): void
    {
        app(EmployeeCreatedHandler::class)->handle($this->createdEvent());

        app(EmployeeUpdatedHandler::class)->handle([
            'time' => '2026-08-02T12:00:00+00:00',
            'data' => [
                'employee_id' => 501,
                'changed_fields' => [
                    'last_name' => ['from' => 'Rossi', 'to' => 'Rossi-Bianchi'],
                ],
            ],
        ]);

        $employee = Employee::with('positions')->find(501);
        $this->assertSame('Rossi-Bianchi', $employee->last_name);
        // Not in the delta → untouched, including every collection.
        $this->assertSame('Marco', $employee->first_name);
        $this->assertCount(1, $employee->positions);
        $this->assertSame('marco@example.com', $employee->primaryEmail());
    }

    public function test_a_collection_delta_replaces_the_whole_collection(): void
    {
        app(EmployeeCreatedHandler::class)->handle($this->createdEvent());

        app(EmployeeUpdatedHandler::class)->handle([
            'time' => '2026-08-02T12:00:00+00:00',
            'data' => [
                'employee_id' => 501,
                'changed_fields' => [
                    'contacts' => [
                        'from' => [],
                        'to' => [
                            ['contact_type' => 'email', 'contact_value' => 'm.rossi@example.com', 'is_primary' => true],
                        ],
                    ],
                ],
            ],
        ]);

        $employee = Employee::with('contacts')->find(501);
        // Hiring delete-and-reinserts child rows, so the delta is the whole
        // array — merging would leave the stale phone behind.
        $this->assertCount(1, $employee->contacts);
        $this->assertSame('m.rossi@example.com', $employee->primaryEmail());
    }

    public function test_a_status_only_delta_recomputes_active_without_losing_stores(): void
    {
        app(EmployeeCreatedHandler::class)->handle($this->createdEvent());

        app(EmployeeUpdatedHandler::class)->handle([
            'time' => '2026-08-02T12:00:00+00:00',
            'data' => [
                'employee_id' => 501,
                'changed_fields' => [
                    'status_histories' => [
                        'from' => [],
                        'to' => [
                            ['id' => 12, 'status' => 'resigned', 'effective_date' => '2026-07-01', 'store' => ['store_number' => '03759-00001']],
                            ['id' => 9, 'status' => 'hired', 'effective_date' => '2026-01-15', 'store' => ['store_number' => '03759-00001']],
                        ],
                    ],
                ],
            ],
        ]);

        $employee = Employee::with('stores')->find(501);
        $this->assertFalse($employee->active);
        $this->assertSame('resigned', $employee->current_status);
        // Store list wasn't in the delta, so it must survive.
        $this->assertCount(1, $employee->stores);
        $this->assertSame('03759-00001', $employee->stores->first()->store_number);
    }

    public function test_an_arriving_humanity_id_fulfils_an_open_sync_request(): void
    {
        app(EmployeeCreatedHandler::class)->handle($this->createdEvent());

        EmployeeSyncRequest::query()->create([
            'employee_id' => 501,
            'status' => 'requested',
            'requested_at' => now(),
            'attempts' => 1,
        ]);

        app(EmployeeUpdatedHandler::class)->handle([
            'time' => '2026-08-02T12:00:00+00:00',
            'data' => [
                'employee_id' => 501,
                'changed_fields' => [
                    'ids' => [
                        'from' => [],
                        'to' => [['id_value' => '88213', 'id_type' => ['label' => 'Humanity ID']]],
                    ],
                ],
            ],
        ]);

        $this->assertSame('88213', Employee::find(501)->humanity_employee_id);
        // This is the moment the unsynced-employee loop closes.
        $this->assertSame('fulfilled', EmployeeSyncRequest::sole()->status);
        $this->assertNotNull(EmployeeSyncRequest::sole()->fulfilled_at);
    }

    public function test_an_out_of_order_event_is_ignored(): void
    {
        app(EmployeeCreatedHandler::class)->handle($this->createdEvent());

        app(EmployeeUpdatedHandler::class)->handle([
            'time' => '2026-08-05T12:00:00+00:00',
            'data' => ['employee_id' => 501, 'changed_fields' => ['first_name' => ['from' => 'Marco', 'to' => 'Marc']]],
        ]);

        // Redelivered older event: JetStream gives no ordering guarantee.
        app(EmployeeUpdatedHandler::class)->handle([
            'time' => '2026-08-03T12:00:00+00:00',
            'data' => ['employee_id' => 501, 'changed_fields' => ['first_name' => ['from' => 'Marco', 'to' => 'STALE']]],
        ]);

        $this->assertSame('Marc', Employee::find(501)->first_name);
    }

    public function test_an_update_before_its_create_throws_so_the_consumer_retries(): void
    {
        $this->expectExceptionMessage('employee 501 not synced yet');

        app(EmployeeUpdatedHandler::class)->handle([
            'time' => '2026-08-02T12:00:00+00:00',
            'data' => ['employee_id' => 501, 'changed_fields' => ['first_name' => ['from' => 'a', 'to' => 'b']]],
        ]);
    }

    public function test_a_redelivered_created_event_is_idempotent(): void
    {
        app(EmployeeCreatedHandler::class)->handle($this->createdEvent());
        app(EmployeeCreatedHandler::class)->handle($this->createdEvent());

        $this->assertSame(1, Employee::count());
        $this->assertCount(2, Employee::with('contacts')->find(501)->contacts);
        $this->assertSame(1, EmployeeExternalId::where('employee_id', 501)->count());
    }
}
