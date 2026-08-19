<?php

namespace Tests\Feature\Humanity;

use App\Models\Employee;
use App\Models\HumanityLocation;
use App\Models\HumanityPosition;
use App\Models\HumanityPositionMap;
use App\Models\Position;
use App\Models\Store;
use App\Services\Humanity\FakeHumanityClient;
use App\Services\Humanity\HumanityPositionResolver;
use App\Services\Scheduling\Exceptions\SchedulingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Store numbers never resemble Humanity's location names, so the pairing is
 * explicit data. These commands are the only supported way to create it —
 * without them a fresh install can't create a single shift.
 */
class MappingCommandsTest extends TestCase
{
    use RefreshDatabase;

    private FakeHumanityClient $humanity;

    protected function setUp(): void
    {
        parent::setUp();

        Store::query()->create([
            'id' => 1,
            'store_number' => '03759-00001',
            'name' => 'Downtown',
            'timezone' => 'America/Chicago',
        ]);

        $this->humanity = app(FakeHumanityClient::class);
        // Deliberately nothing like the store number — the realistic case.
        $this->humanity->seedLocation('LOC-77', 'Pizza Downtown #4', 'America/Chicago');

        HumanityPosition::query()->create([
            'humanity_position_id' => 'POS-11',
            'humanity_location_id' => 'LOC-77',
            'name' => 'Kitchen',
            'is_active' => true,
        ]);
    }

    public function test_it_maps_a_store_whose_number_matches_nothing_in_humanity(): void
    {
        $this->artisan('humanity:map-location', ['--store' => '03759-00001', '--location' => 'LOC-77'])
            ->assertExitCode(0);

        $row = HumanityLocation::sole();
        $this->assertSame(1, $row->store_id);
        $this->assertSame('LOC-77', $row->humanity_location_id);
        $this->assertSame('Pizza Downtown #4', $row->name);
    }

    public function test_it_refuses_a_location_id_humanity_does_not_have(): void
    {
        // A typo'd id would otherwise surface much later as an opaque
        // Humanity error on the first shift write.
        $this->artisan('humanity:map-location', ['--store' => '03759-00001', '--location' => 'LOC-99'])
            ->expectsOutputToContain('does not exist')
            ->assertExitCode(1);

        $this->assertSame(0, HumanityLocation::count());
    }

    public function test_it_refuses_to_map_one_humanity_location_to_two_stores(): void
    {
        Store::query()->create(['id' => 2, 'store_number' => '03759-00002', 'timezone' => 'America/Chicago']);

        $this->artisan('humanity:map-location', ['--store' => '03759-00001', '--location' => 'LOC-77'])
            ->assertExitCode(0);

        // Sharing a location would silently merge two restaurants' schedules.
        $this->artisan('humanity:map-location', ['--store' => '03759-00002', '--location' => 'LOC-77'])
            ->expectsOutputToContain('already mapped')
            ->assertExitCode(1);

        $this->assertSame(1, HumanityLocation::count());
    }

    public function test_mapping_a_location_warns_that_a_default_position_is_still_missing(): void
    {
        $this->artisan('humanity:map-location', ['--store' => '03759-00001', '--location' => 'LOC-77'])
            ->expectsOutputToContain('POSITION_NOT_MAPPED')
            ->assertExitCode(0);
    }

    public function test_it_maps_a_store_default_position(): void
    {
        $this->artisan('humanity:map-location', ['--store' => '03759-00001', '--location' => 'LOC-77']);

        $this->artisan('humanity:map-position', [
            '--store' => '03759-00001',
            '--humanity-position' => 'POS-11',
            '--default' => true,
        ])->assertExitCode(0);

        $row = HumanityPositionMap::sole();
        $this->assertNull($row->position_id);
        $this->assertTrue($row->is_default);
        $this->assertSame('POS-11', $row->humanity_position_id);
    }

    public function test_position_mapping_requires_the_location_first(): void
    {
        $this->artisan('humanity:map-position', [
            '--store' => '03759-00001',
            '--humanity-position' => 'POS-11',
            '--default' => true,
        ])->expectsOutputToContain('not mapped to a Humanity location')
            ->assertExitCode(1);
    }

    public function test_it_rejects_a_position_belonging_to_another_location(): void
    {
        HumanityPosition::query()->create([
            'humanity_position_id' => 'POS-99',
            'humanity_location_id' => 'LOC-OTHER',
            'name' => 'Someone else kitchen',
            'is_active' => true,
        ]);

        $this->artisan('humanity:map-location', ['--store' => '03759-00001', '--location' => 'LOC-77']);

        // Writing against another location's position lands the shift in the
        // wrong restaurant.
        $this->artisan('humanity:map-position', [
            '--store' => '03759-00001',
            '--humanity-position' => 'POS-99',
            '--default' => true,
        ])->expectsOutputToContain('not available for this store')
            ->assertExitCode(1);
    }

    public function test_the_resolver_works_once_both_mappings_exist(): void
    {
        $store = Store::sole();
        $resolver = app(HumanityPositionResolver::class);

        // Before: both throw, which is what a fresh install hits.
        try {
            $resolver->locationId($store);
            $this->fail('Expected STORE_NOT_MAPPED.');
        } catch (SchedulingException $e) {
            $this->assertSame('STORE_NOT_MAPPED', $e->errorCode);
        }

        $this->artisan('humanity:map-location', ['--store' => '03759-00001', '--location' => 'LOC-77']);

        try {
            $resolver->positionId($store);
            $this->fail('Expected POSITION_NOT_MAPPED.');
        } catch (SchedulingException $e) {
            $this->assertSame('POSITION_NOT_MAPPED', $e->errorCode);
        }

        $this->artisan('humanity:map-position', [
            '--store' => '03759-00001',
            '--humanity-position' => 'POS-11',
            '--default' => true,
        ]);

        // After: the pair the shift writer actually needs.
        $this->assertSame('LOC-77', $resolver->locationId($store));
        $this->assertSame('POS-11', $resolver->positionId($store));
    }

    public function test_a_mapped_employee_position_beats_the_store_default(): void
    {
        $store = Store::sole();

        HumanityPosition::query()->create([
            'humanity_position_id' => 'POS-22',
            'humanity_location_id' => 'LOC-77',
            'name' => 'Delivery',
            'is_active' => true,
        ]);

        Position::query()->create(['id' => 4, 'label' => 'Driver']);

        $employee = Employee::query()->create([
            'id' => 501, 'first_name' => 'A', 'last_name' => 'B', 'active' => true,
        ]);
        $employee->positions()->attach(4, ['is_primary' => true]);

        $this->artisan('humanity:map-location', ['--store' => '03759-00001', '--location' => 'LOC-77']);
        $this->artisan('humanity:map-position', ['--store' => '03759-00001', '--humanity-position' => 'POS-11', '--default' => true]);
        $this->artisan('humanity:map-position', ['--store' => '03759-00001', '--humanity-position' => 'POS-22', '--position' => 4]);

        $resolver = app(HumanityPositionResolver::class);

        $this->assertSame('POS-22', $resolver->positionId($store, $employee->load('positions')));
        // An employee with no mapped position still falls back cleanly.
        $this->assertSame('POS-11', $resolver->positionId($store));
    }

    public function test_unmapping_the_default_is_flagged_as_breaking_scheduling(): void
    {
        $this->artisan('humanity:map-location', ['--store' => '03759-00001', '--location' => 'LOC-77']);
        $this->artisan('humanity:map-position', ['--store' => '03759-00001', '--humanity-position' => 'POS-11', '--default' => true]);

        $this->artisan('humanity:map-position', ['--store' => '03759-00001', '--default' => true, '--unmap' => true])
            ->expectsOutputToContain('can no longer be scheduled')
            ->assertExitCode(0);

        $this->assertSame(0, HumanityPositionMap::count());
    }

    public function test_auto_map_prefers_the_store_scoped_position_over_the_global_one(): void
    {
        $this->artisan('humanity:map-location', ['--store' => '03759-00001', '--location' => 'LOC-77']);

        Position::query()->create(['id' => 10, 'label' => 'Crew Member']);

        // The real account has both a bare label and a store-suffixed one for
        // the same role — the store-scoped one must win, not read as ambiguous.
        HumanityPosition::query()->create([
            'humanity_position_id' => 'POS-GLOBAL', 'humanity_location_id' => null,
            'name' => 'Crew Member', 'is_active' => true,
        ]);
        HumanityPosition::query()->create([
            'humanity_position_id' => 'POS-STORE', 'humanity_location_id' => 'LOC-77',
            'name' => 'Crew Member - Downtown', 'is_active' => true,
        ]);

        $this->artisan('humanity:map-position', ['--auto-map' => true, '--store' => '03759-00001'])
            ->assertExitCode(0);

        $row = HumanityPositionMap::query()->where('position_id', 10)->sole();
        $this->assertSame('POS-STORE', $row->humanity_position_id);
        $this->assertFalse((bool) $row->is_default);
    }

    public function test_auto_map_reports_two_store_scoped_matches_as_ambiguous(): void
    {
        $this->artisan('humanity:map-location', ['--store' => '03759-00001', '--location' => 'LOC-77']);

        Position::query()->create(['id' => 10, 'label' => 'Crew Member']);

        HumanityPosition::query()->create([
            'humanity_position_id' => 'POS-A', 'humanity_location_id' => 'LOC-77',
            'name' => 'Crew Member - A', 'is_active' => true,
        ]);
        HumanityPosition::query()->create([
            'humanity_position_id' => 'POS-B', 'humanity_location_id' => 'LOC-77',
            'name' => 'Crew Member - B', 'is_active' => true,
        ]);

        $this->artisan('humanity:map-position', ['--auto-map' => true, '--store' => '03759-00001'])
            ->expectsOutputToContain('Ambiguous')
            ->assertExitCode(1);

        $this->assertSame(0, HumanityPositionMap::query()->where('position_id', 10)->count());
    }

    public function test_auto_map_leaves_an_unmatched_position_unmapped(): void
    {
        $this->artisan('humanity:map-location', ['--store' => '03759-00001', '--location' => 'LOC-77']);

        // setUp() only seeds a "Kitchen" Humanity position — nothing matches "Manager".
        Position::query()->create(['id' => 10, 'label' => 'Manager']);

        $this->artisan('humanity:map-position', ['--auto-map' => true, '--store' => '03759-00001'])
            ->assertExitCode(0);

        $this->assertSame(0, HumanityPositionMap::query()->where('position_id', 10)->count());
    }

    public function test_auto_map_with_store_option_only_touches_that_store(): void
    {
        Store::query()->create(['id' => 2, 'store_number' => '03759-00002', 'timezone' => 'America/Chicago']);

        $this->artisan('humanity:map-location', ['--store' => '03759-00001', '--location' => 'LOC-77']);
        $this->humanity->seedLocation('LOC-88', 'Pizza Uptown #2', 'America/Chicago');
        $this->artisan('humanity:map-location', ['--store' => '03759-00002', '--location' => 'LOC-88']);

        Position::query()->create(['id' => 10, 'label' => 'Crew Member']);
        HumanityPosition::query()->create([
            'humanity_position_id' => 'POS-S1', 'humanity_location_id' => 'LOC-77',
            'name' => 'Crew Member - Downtown', 'is_active' => true,
        ]);
        HumanityPosition::query()->create([
            'humanity_position_id' => 'POS-S2', 'humanity_location_id' => 'LOC-88',
            'name' => 'Crew Member - Uptown', 'is_active' => true,
        ]);

        $this->artisan('humanity:map-position', ['--auto-map' => true, '--store' => '03759-00001'])
            ->assertExitCode(0);

        $this->assertSame(1, HumanityPositionMap::query()->where('store_id', 1)->count());
        $this->assertSame(0, HumanityPositionMap::query()->where('store_id', 2)->count());
    }

    public function test_auto_map_without_store_covers_every_store_without_prompting(): void
    {
        Store::query()->create(['id' => 2, 'store_number' => '03759-00002', 'timezone' => 'America/Chicago']);
        $this->humanity->seedLocation('LOC-88', 'Pizza Uptown #2', 'America/Chicago');

        $this->artisan('humanity:map-location', ['--store' => '03759-00001', '--location' => 'LOC-77'])->assertExitCode(0);
        $this->artisan('humanity:map-location', ['--store' => '03759-00002', '--location' => 'LOC-88'])->assertExitCode(0);

        Position::query()->create(['id' => 10, 'label' => 'Crew Member']);
        HumanityPosition::query()->create([
            'humanity_position_id' => 'POS-S1', 'humanity_location_id' => 'LOC-77',
            'name' => 'Crew Member - Downtown', 'is_active' => true,
        ]);
        HumanityPosition::query()->create([
            'humanity_position_id' => 'POS-S2', 'humanity_location_id' => 'LOC-88',
            'name' => 'Crew Member - Uptown', 'is_active' => true,
        ]);

        // No --store: must loop every store without ever prompting interactively.
        $this->artisan('humanity:map-position', ['--auto-map' => true])
            ->assertExitCode(0);

        $this->assertSame('POS-S1', HumanityPositionMap::query()->where('store_id', 1)->value('humanity_position_id'));
        $this->assertSame('POS-S2', HumanityPositionMap::query()->where('store_id', 2)->value('humanity_position_id'));
    }

    public function test_auto_map_is_idempotent(): void
    {
        $this->artisan('humanity:map-location', ['--store' => '03759-00001', '--location' => 'LOC-77']);

        Position::query()->create(['id' => 10, 'label' => 'Crew Member']);
        HumanityPosition::query()->create([
            'humanity_position_id' => 'POS-STORE', 'humanity_location_id' => 'LOC-77',
            'name' => 'Crew Member - Downtown', 'is_active' => true,
        ]);

        $this->artisan('humanity:map-position', ['--auto-map' => true, '--store' => '03759-00001'])->assertExitCode(0);
        $this->artisan('humanity:map-position', ['--auto-map' => true, '--store' => '03759-00001'])->assertExitCode(0);

        $this->assertSame(1, HumanityPositionMap::query()->where('position_id', 10)->count());
    }

    public function test_auto_map_does_not_overwrite_a_disagreeing_manual_mapping_without_force(): void
    {
        $this->artisan('humanity:map-location', ['--store' => '03759-00001', '--location' => 'LOC-77']);

        Position::query()->create(['id' => 10, 'label' => 'Crew Member']);
        HumanityPosition::query()->create([
            'humanity_position_id' => 'POS-STORE', 'humanity_location_id' => 'LOC-77',
            'name' => 'Crew Member - Downtown', 'is_active' => true,
        ]);

        // A human previously pointed this position somewhere else on purpose.
        $this->artisan('humanity:map-position', [
            '--store' => '03759-00001', '--humanity-position' => 'POS-11', '--position' => 10,
        ]);

        $this->artisan('humanity:map-position', ['--auto-map' => true, '--store' => '03759-00001'])
            ->expectsOutputToContain('already mapped')
            ->assertExitCode(0);

        $this->assertSame('POS-11', HumanityPositionMap::query()->where('position_id', 10)->value('humanity_position_id'));

        $this->artisan('humanity:map-position', ['--auto-map' => true, '--store' => '03759-00001', '--force' => true])
            ->assertExitCode(0);

        $this->assertSame('POS-STORE', HumanityPositionMap::query()->where('position_id', 10)->value('humanity_position_id'));
    }

    public function test_auto_map_never_touches_the_default_row(): void
    {
        $this->artisan('humanity:map-location', ['--store' => '03759-00001', '--location' => 'LOC-77']);
        $this->artisan('humanity:map-position', ['--store' => '03759-00001', '--humanity-position' => 'POS-11', '--default' => true]);

        Position::query()->create(['id' => 10, 'label' => 'Crew Member']);
        HumanityPosition::query()->create([
            'humanity_position_id' => 'POS-STORE', 'humanity_location_id' => 'LOC-77',
            'name' => 'Crew Member - Downtown', 'is_active' => true,
        ]);

        $this->artisan('humanity:map-position', ['--auto-map' => true, '--store' => '03759-00001'])
            ->assertExitCode(0);

        $default = HumanityPositionMap::query()->whereNull('position_id')->sole();
        $this->assertSame('POS-11', $default->humanity_position_id);
        $this->assertTrue((bool) $default->is_default);
    }
}
