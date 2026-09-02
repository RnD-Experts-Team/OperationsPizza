<?php

namespace Tests\Feature\Api;

use App\Models\Employee;
use App\Models\EmployeeStore;
use App\Models\HumanityLocation;
use App\Models\HumanityPosition;
use App\Models\HumanityPositionMap;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use App\Services\Humanity\FakeHumanityClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScheduleApiTest extends TestCase
{
    use RefreshDatabase;

    private FakeHumanityClient $humanity;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.auth_server.base_url' => 'http://auth.test',
            'services.auth_server.verify_path' => '/api/v1/auth/token/verify',
            'services.auth_server.service_name' => 'operations-system',
            'services.auth_server.call_token' => 'service-token',
            'humanity.writes_enabled' => true,
        ]);

        // pizzasys is the auth source of truth; the middleware calls it on
        // every request, so faking it here exercises the real chain.
        $this->fakeAuthServer();

        User::query()->create(['id' => 9, 'name' => 'Dana', 'email' => 'dana@example.com']);

        $store = Store::query()->create([
            'id' => 1,
            'store_number' => '03759-00001',
            'name' => 'Downtown',
            'timezone' => 'America/Chicago',
        ]);
        $store->settings();

        HumanityLocation::query()->create(['store_id' => 1, 'humanity_location_id' => 'LOC1', 'name' => 'Downtown']);
        HumanityPosition::query()->create(['humanity_position_id' => 'POS1', 'humanity_location_id' => 'LOC1', 'name' => 'Kitchen']);
        HumanityPositionMap::query()->create(['store_id' => 1, 'position_label' => null, 'humanity_position_id' => 'POS1', 'is_default' => true]);

        Employee::query()->create([
            'id' => 501,
            'first_name' => 'Marco',
            'last_name' => 'Rossi',
            'active' => true,
            'current_status' => 'hired',
            'humanity_employee_id' => '88213',
        ]);

        EmployeeStore::query()->create([
            'employee_id' => 501,
            'store_number' => '03759-00001',
            'store_id' => 1,
            'status' => 'hired',
            'active' => true,
        ]);

        $this->humanity = app(FakeHumanityClient::class);
        $this->humanity->seedLocation('LOC1');
        $this->humanity->seedPosition('POS1', 'Kitchen', 'LOC1');
        $this->humanity->seedEmployee('88213', ['eid' => '501']);
    }

    private bool $tokenActive = true;
    private bool $tokenAuthorized = true;

    /**
     * Registered once. Http::fake() MERGES stubs and the first match wins, so
     * re-faking mid-test would silently keep the original response — and any
     * unmatched request escapes to the real network.
     */
    private function fakeAuthServer(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            'auth.test/*' => function () {
                return Http::response([
                    'active' => $this->tokenActive,
                    'subject_type' => 'user',
                    'user' => ['id' => 9, 'name' => 'Dana', 'email' => 'dana@example.com'],
                    'roles' => ['Store Manager'],
                    'permissions' => [],
                    'ext' => ['authorized' => $this->tokenAuthorized],
                ]);
            },
        ]);
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer 1|test-token', 'Accept' => 'application/json'];
    }

    public function test_the_week_endpoint_returns_the_whole_grid_payload(): void
    {
        $response = $this->getJson(
            '/api/v1/stores/03759-00001/schedule/week?week_start=2026-08-04',
            $this->headers()
        );

        $response->assertOk()
            ->assertJsonStructure(['data' => [
                'week' => ['start', 'end', 'label', 'week_start_dow', 'day_dates', 'full_dates'],
                'store' => ['store_number', 'timezone', 'open_time', 'close_time', 'overtime_threshold_hours'],
                'employees', 'departments', 'shifts', 'actual_shifts',
                'availability', 'time_off', 'published', 'stats', 'conflicts',
            ]]);

        // The business week starts on Tuesday, and 2026-08-04 IS a Tuesday.
        $this->assertSame('2026-08-04', $response->json('data.week.start'));
        $this->assertSame('2026-08-10', $response->json('data.week.end'));
        $this->assertSame(2, $response->json('data.week.week_start_dow'));
    }

    public function test_a_mid_week_date_snaps_back_to_the_week_start(): void
    {
        // Thursday. A client asking for a day rather than a boundary must not
        // get a week offset by two days.
        $response = $this->getJson(
            '/api/v1/stores/03759-00001/schedule/week?week_start=2026-08-06',
            $this->headers()
        );

        $this->assertSame('2026-08-04', $response->json('data.week.start'));
    }

    public function test_the_roster_carries_a_stable_colour_and_avatar(): void
    {
        $response = $this->getJson('/api/v1/stores/03759-00001/schedule/week', $this->headers());

        $employee = $response->json('data.employees.0');

        $this->assertSame('501', $employee['id']);
        $this->assertSame('Marco Rossi', $employee['name']);
        $this->assertSame('MR', $employee['avatar']);
        $this->assertContains($employee['color'], \App\Services\Scheduling\EmployeePresenter::COLORS);
        $this->assertTrue($employee['synced']);
    }

    public function test_creating_a_shift_returns_the_dto_with_a_server_computed_day_index(): void
    {
        $response = $this->postJson('/api/v1/stores/03759-00001/shifts', [
            'employee_id' => 501,
            'shift_date' => '2026-08-06',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'label' => 'Morning',
            'shift_type' => 'morning',
        ], $this->headers());

        $response->assertCreated();

        // 2026-08-06 is Thursday; with a Tuesday week start that's index 2.
        $this->assertSame(2, $response->json('data.day_index'));
        $this->assertSame(480, $response->json('data.duration_minutes'));
        $this->assertSame('501', $response->json('data.employee_id'));
        $this->assertNotNull($response->json('data.humanity_shift_id'));
    }

    public function test_position_label_override_is_applied_on_create(): void
    {
        // Regression: ShiftStoreRequest used to validate the dead `position_id`
        // (int) field instead of `position_label` (string), so an explicit
        // override was silently dropped before it ever reached the writer.
        HumanityPosition::query()->create(['humanity_position_id' => 'POS2', 'humanity_location_id' => 'LOC1', 'name' => 'Cashier']);
        HumanityPositionMap::query()->create(['store_id' => 1, 'position_label' => 'Cashier', 'humanity_position_id' => 'POS2', 'is_default' => false]);

        $response = $this->postJson('/api/v1/stores/03759-00001/shifts', [
            'employee_id' => 501,
            'shift_date' => '2026-08-06',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'position_label' => 'Cashier',
        ], $this->headers());

        $response->assertCreated();

        $shift = Shift::query()->find($response->json('data.shift_id'));
        $this->assertSame('POS2', $shift->humanity_position_id);
    }

    public function test_bulk_create_shifts_queues_and_writes_through(): void
    {
        $response = $this->postJson('/api/v1/stores/03759-00001/schedule/bulk/create-shifts', [
            'week_start' => '2026-08-04',
            'shifts' => [
                ['employee_id' => 501, 'day_index' => 0, 'start_time' => '09:00', 'end_time' => '17:00', 'label' => 'Morning'],
            ],
        ], $this->headers());

        $response->assertStatus(202);
        $this->assertSame('queued', $response->json('data.status'));
        $this->assertSame(1, $response->json('data.total'));

        app(\App\Jobs\ProcessBulkOperationJob::class, ['operationId' => $response->json('data.id')])
            ->handle(app(\App\Services\Scheduling\ShiftWriteService::class));

        $this->assertSame(1, Shift::query()->whereBetween('shift_date', ['2026-08-04', '2026-08-10'])->count());
    }

    public function test_a_conflicting_shift_returns_409_with_an_actionable_code(): void
    {
        $payload = [
            'employee_id' => 501,
            'shift_date' => '2026-08-06',
            'start_time' => '09:00',
            'end_time' => '17:00',
        ];

        $this->postJson('/api/v1/stores/03759-00001/shifts', $payload, $this->headers())->assertCreated();

        $this->postJson('/api/v1/stores/03759-00001/shifts', $payload, $this->headers())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SHIFT_CONFLICT');
    }

    public function test_force_lets_a_manager_schedule_anyway(): void
    {
        $payload = [
            'employee_id' => 501,
            'shift_date' => '2026-08-06',
            'start_time' => '09:00',
            'end_time' => '17:00',
        ];

        $this->postJson('/api/v1/stores/03759-00001/shifts', $payload, $this->headers())->assertCreated();

        $this->postJson('/api/v1/stores/03759-00001/shifts', $payload + ['force' => true], $this->headers())
            ->assertCreated();
    }

    public function test_a_nonexistent_local_time_is_rejected_with_a_specific_code(): void
    {
        // 02:30 on 2026-03-08 does not exist in America/Chicago.
        $this->postJson('/api/v1/stores/03759-00001/shifts', [
            'employee_id' => 501,
            'shift_date' => '2026-03-08',
            'start_time' => '02:30',
            'end_time' => '06:00',
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_LOCAL_TIME');
    }

    public function test_an_unsynced_employee_returns_a_resumable_409(): void
    {
        Employee::query()->whereKey(501)->update(['humanity_employee_id' => null]);

        $response = $this->postJson('/api/v1/stores/03759-00001/shifts', [
            'employee_id' => 501,
            'shift_date' => '2026-08-06',
            'start_time' => '09:00',
            'end_time' => '17:00',
        ], $this->headers());

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'EMPLOYEE_NOT_SYNCED')
            ->assertJsonPath('error.employee_name', 'Marco Rossi')
            ->assertJsonPath('error.sync_status', 'requested');

        // The UI polls this rather than losing the manager's typed shift.
        $this->assertStringContainsString('sync-status', $response->json('error.poll_url'));
        $this->assertSame(0, Shift::count());
    }

    public function test_a_tcp_linked_employee_waits_on_tcps_connector_without_re_requesting_a_tcp_push(): void
    {
        // Already in TCP, just not propagated to Humanity yet (no eid seeded
        // on the fake client) — this must NOT re-fire tcp_sync_requested,
        // since that would ask HiringPizza to push someone who's already there.
        Employee::query()->whereKey(501)->update([
            'humanity_employee_id' => null,
            'tcp_employee_id' => '9004321',
        ]);

        $response = $this->postJson('/api/v1/stores/03759-00001/shifts', [
            'employee_id' => 501,
            'shift_date' => '2026-08-06',
            'start_time' => '09:00',
            'end_time' => '17:00',
        ], $this->headers());

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'EMPLOYEE_NOT_SYNCED')
            ->assertJsonPath('error.sync_status', 'awaiting_tcp_connector');

        $this->assertSame(0, \App\Models\EmployeeSyncRequest::query()->count());
        $this->assertSame(0, \App\Models\OperationsOutboxEvent::query()->count());
    }

    public function test_deleting_a_published_shift_requires_confirmation(): void
    {
        $created = $this->postJson('/api/v1/stores/03759-00001/shifts', [
            'employee_id' => 501,
            'shift_date' => '2026-08-06',
            'start_time' => '09:00',
            'end_time' => '17:00',
        ], $this->headers());

        $shiftId = $created->json('data.shift_id');
        Shift::query()->whereKey($shiftId)->update(['is_published' => true]);

        // Employees may already have been notified by Humanity.
        $this->deleteJson("/api/v1/stores/03759-00001/shifts/{$shiftId}", [], $this->headers())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SHIFT_PUBLISHED');

        $this->deleteJson("/api/v1/stores/03759-00001/shifts/{$shiftId}?confirm=true", [], $this->headers())
            ->assertNoContent();
    }

    public function test_an_unknown_store_is_a_clean_404(): void
    {
        $this->getJson('/api/v1/stores/NOPE/schedule/week', $this->headers())->assertNotFound();
    }

    public function test_a_request_without_a_token_is_rejected(): void
    {
        $this->getJson('/api/v1/stores/03759-00001/schedule/week')->assertUnauthorized();
    }

    public function test_an_unauthorized_subject_is_rejected(): void
    {
        // pizzasys says the token is valid but this route isn't allowed.
        $this->tokenAuthorized = false;

        $this->getJson('/api/v1/stores/03759-00001/schedule/week', $this->headers())->assertForbidden();
    }

    public function test_the_sync_status_endpoint_reports_the_link(): void
    {
        $this->getJson('/api/v1/stores/03759-00001/employees/501/sync-status', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.synced', true)
            ->assertJsonPath('data.humanity_employee_id', '88213');
    }

    public function test_an_availability_override_can_be_deleted_by_its_raw_id(): void
    {
        $override = \App\Models\ScheduleAvailabilityOverride::query()->create([
            'store_id' => 1,
            'employee_id' => 501,
            'scope' => 'weekly',
            'day_of_week' => 2,
            'all_day' => true,
        ]);

        $this->deleteJson("/api/v1/stores/03759-00001/availability-overrides/{$override->id}", [], $this->headers())
            ->assertNoContent();

        $this->assertSoftDeleted($override);
    }

    public function test_an_availability_override_can_be_deleted_by_the_week_grids_composite_id(): void
    {
        // The week grid's availability projection labels each override
        // "override-{id}-{dayIndex}" and echoes that id back verbatim when
        // the manager removes it — the route must resolve it too, not just
        // the bare database id.
        $override = \App\Models\ScheduleAvailabilityOverride::query()->create([
            'store_id' => 1,
            'employee_id' => 501,
            'scope' => 'weekly',
            'day_of_week' => 2,
            'all_day' => true,
        ]);

        $this->deleteJson(
            "/api/v1/stores/03759-00001/availability-overrides/override-{$override->id}-3",
            [],
            $this->headers()
        )->assertNoContent();

        $this->assertSoftDeleted($override);
    }

    public function test_an_unresolvable_override_id_is_a_clean_404(): void
    {
        $this->deleteJson('/api/v1/stores/03759-00001/availability-overrides/not-an-id', [], $this->headers())
            ->assertNotFound();
    }
}
