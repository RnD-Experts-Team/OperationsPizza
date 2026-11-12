<?php

namespace Tests\Feature\Tcp;

use App\Models\Store;
use App\Models\TcpJobCode;
use App\Services\Scheduling\Exceptions\SchedulingException;
use App\Services\Tcp\TcpJobCodeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Crew Member - AT" has no TCP job code of its own and clocks in as "Crew Member". */
class TcpJobCodeVariantTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::query()->create([
            'id' => 1,
            'store_number' => '03795-00012',
            'name' => 'Store 12',
            'timezone' => 'America/Chicago',
        ]);

        TcpJobCode::query()->create([
            'tcp_job_code_id' => '37951201', 'description' => 'Crew Member - 3795-12',
            'store_number' => '03795-00012', 'clockable' => true, 'is_active' => true,
        ]);
    }

    public function test_a_variant_label_uses_its_base_positions_job_code(): void
    {
        $this->assertSame('37951201', app(TcpJobCodeResolver::class)->resolve($this->store, null, 'Crew Member - AT'));
    }

    public function test_a_variant_with_its_own_job_code_keeps_it(): void
    {
        TcpJobCode::query()->create([
            'tcp_job_code_id' => '37951209', 'description' => 'Crew Member - AT - 3795-12',
            'store_number' => '03795-00012', 'clockable' => true, 'is_active' => true,
        ]);

        $this->assertSame('37951209', app(TcpJobCodeResolver::class)->resolve($this->store, null, 'Crew Member - AT'));
    }

    public function test_any_suffix_listed_in_the_env_falls_back_to_its_base(): void
    {
        // The list comes from TCP_POSITION_VARIANT_SUFFIXES — whatever endings are
        // listed there, not just AT.
        config(['operations.position_variant_suffixes' => ['AT', 'Management Trainee', 'Weekend']]);

        $resolver = app(TcpJobCodeResolver::class);

        $this->assertSame('37951201', $resolver->resolve($this->store, null, 'Crew Member - management trainee'));
        $this->assertSame('37951201', $resolver->resolve($this->store, null, 'Crew Member - Weekend'));
    }

    public function test_an_unconfigured_suffix_is_not_stripped(): void
    {
        config(['operations.position_variant_suffixes' => []]);

        $this->expectException(SchedulingException::class);

        app(TcpJobCodeResolver::class)->resolve($this->store, null, 'Crew Member - AT');
    }
}
