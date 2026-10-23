<?php

namespace Tests\Feature\Api;

use App\Models\Employee;
use App\Models\EmployeeSkill;
use App\Models\EmployeeStore;
use App\Models\Skill;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SkillsApiTest extends TestCase
{
    use RefreshDatabase;

    private Skill $dough;
    private Skill $register;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.auth_server.base_url' => 'http://auth.test',
            'services.auth_server.verify_path' => '/api/v1/auth/token/verify',
            'services.auth_server.service_name' => 'operations-system',
            'services.auth_server.call_token' => 'service-token',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'auth.test/*' => Http::response([
                'active' => true,
                'subject_type' => 'user',
                'user' => ['id' => 9, 'name' => 'Dana', 'email' => 'dana@example.com'],
                'roles' => ['Store Manager'],
                'permissions' => [],
                'ext' => ['authorized' => true],
            ]),
        ]);

        User::query()->create(['id' => 9, 'name' => 'Dana', 'email' => 'dana@example.com']);

        Store::query()->create(['id' => 1, 'store_number' => '03759-00001', 'name' => 'Downtown', 'timezone' => 'America/Chicago']);
        Store::query()->create(['id' => 2, 'store_number' => '03759-00002', 'name' => 'Uptown', 'timezone' => 'America/Chicago']);

        // The migration seeds the six skills.
        $this->dough = Skill::query()->where('name', 'Dough')->firstOrFail();
        $this->register = Skill::query()->where('name', 'Register')->firstOrFail();

        $this->employee(501, 'Marco', now()->subYears(30)->toDateString());
        $this->employee(502, 'Lia', now()->subYears(16)->toDateString());
        $this->employee(503, 'Nico', null);
        $this->employee(504, 'Elsewhere', now()->subYears(30)->toDateString(), '03759-00002');
    }

    private function employee(int $id, string $firstName, ?string $birthDate, string $storeNumber = '03759-00001'): void
    {
        Employee::query()->create([
            'id' => $id,
            'first_name' => $firstName,
            'last_name' => 'Test',
            'birth_date' => $birthDate,
            'active' => true,
            'current_status' => 'hired',
        ]);

        EmployeeStore::query()->create([
            'employee_id' => $id,
            'store_number' => $storeNumber,
            'store_id' => $storeNumber === '03759-00001' ? 1 : 2,
            'status' => 'hired',
            'active' => true,
        ]);
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer 1|test-token', 'Accept' => 'application/json'];
    }

    private function rate(int $employeeId, Skill $skill, int $rating, ?string $notes = null)
    {
        return $this->postJson(
            "/api/v1/stores/03759-00001/employees/{$employeeId}/skills/{$skill->id}",
            ['rating' => $rating, 'notes' => $notes],
            $this->headers()
        );
    }

    public function test_the_list_starts_with_the_six_skills_and_dough_and_landing_are_adults_only(): void
    {
        $response = $this->getJson('/api/v1/skills', $this->headers())->assertOk();

        $this->assertSame(
            ['Opening', 'Closing', 'Dough', 'Making', 'Landing', 'Register'],
            array_column($response->json('data'), 'name')
        );

        $restricted = array_values(array_filter($response->json('data'), fn ($skill) => $skill['min_age'] !== null));
        $this->assertSame(['Dough', 'Landing'], array_column($restricted, 'name'));
        $this->assertSame(18, $restricted[0]['min_age']);
        $this->assertNotEmpty($restricted[0]['restriction_note']);
    }

    public function test_a_skill_can_be_created_updated_and_deleted_with_its_ratings(): void
    {
        $id = $this->postJson('/api/v1/skills', ['name' => 'Oven', 'min_age' => 18, 'restriction_note' => 'Hot.'], $this->headers())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Oven')
            ->json('data.id');

        $this->postJson('/api/v1/skills', ['name' => 'Oven'], $this->headers())->assertUnprocessable();

        $this->postJson("/api/v1/skills/{$id}", ['min_age' => null, 'restriction_note' => null], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.name', 'Oven')
            ->assertJsonPath('data.min_age', null);

        $this->rate(501, Skill::query()->find($id), 4)->assertOk();

        $this->deleteJson("/api/v1/skills/{$id}", [], $this->headers())->assertNoContent();

        $this->assertNull(Skill::query()->find($id));
        $this->assertSame(0, EmployeeSkill::query()->where('skill_id', $id)->count());
    }

    public function test_a_rating_is_created_then_replaced(): void
    {
        $this->rate(501, $this->register, 3, 'Slow at rush')
            ->assertOk()
            ->assertJsonPath('data.rating', 3)
            ->assertJsonPath('data.notes', 'Slow at rush')
            ->assertJsonPath('data.alert', false);

        $this->rate(501, $this->register, 5)->assertOk()->assertJsonPath('data.notes', null);

        $this->assertSame(1, EmployeeSkill::query()->where('employee_id', 501)->count());
        $this->assertSame(5, EmployeeSkill::query()->where('employee_id', 501)->value('rating'));

        $this->rate(501, $this->register, 6)->assertUnprocessable();
    }

    public function test_the_rating_is_the_employees_not_the_stores(): void
    {
        EmployeeStore::query()->create([
            'employee_id' => 501, 'store_number' => '03759-00002', 'store_id' => 2, 'status' => 'hired', 'active' => true,
        ]);

        $this->rate(501, $this->register, 4)->assertOk();

        $skills = $this->getJson('/api/v1/stores/03759-00002/employees/501/skills', $this->headers())
            ->assertOk()
            ->json('data.skills');

        $register = collect($skills)->firstWhere('skill.name', 'Register');
        $this->assertSame(4, $register['rating']);
    }

    public function test_an_employee_of_another_store_is_not_found(): void
    {
        $this->getJson('/api/v1/stores/03759-00001/employees/504/skills', $this->headers())->assertNotFound();
        $this->rate(504, $this->register, 4)->assertNotFound();
    }

    public function test_a_minor_rated_on_dough_is_saved_with_an_alert(): void
    {
        $this->rate(502, $this->dough, 2)
            ->assertOk()
            ->assertJsonPath('data.rating', 2)
            ->assertJsonPath('data.restricted', true)
            ->assertJsonPath('data.alert', true);

        $this->assertSame(1, EmployeeSkill::query()->where('employee_id', 502)->count());

        $response = $this->getJson('/api/v1/stores/03759-00001/employees/502/skills', $this->headers())->assertOk();

        $this->assertTrue($response->json('data.employee.is_minor'));
        $this->assertArrayNotHasKey('birth_date', $response->json('data.employee'));

        $skills = collect($response->json('data.skills'))->keyBy('skill.name');
        $this->assertTrue($skills['Dough']['alert']);
        // Under age for Landing too, but not rated on it: restricted, no alert.
        $this->assertTrue($skills['Landing']['restricted']);
        $this->assertFalse($skills['Landing']['alert']);
        $this->assertFalse($skills['Register']['restricted']);
    }

    public function test_an_adult_or_an_unknown_birth_date_raises_no_alert(): void
    {
        $this->rate(501, $this->dough, 5)->assertOk()->assertJsonPath('data.alert', false);
        $this->rate(503, $this->dough, 5)->assertOk()->assertJsonPath('data.alert', false);

        $this->getJson('/api/v1/stores/03759-00001/employees/501/skills', $this->headers())
            ->assertJsonPath('data.employee.is_minor', false);
        $this->getJson('/api/v1/stores/03759-00001/employees/503/skills', $this->headers())
            ->assertJsonPath('data.employee.is_minor', null);
    }

    public function test_the_alert_clears_on_the_eighteenth_birthday(): void
    {
        Employee::query()->whereKey(502)->update(['birth_date' => now()->subYears(18)->addDay()->toDateString()]);
        $this->rate(502, $this->dough, 3)->assertJsonPath('data.alert', true);

        Employee::query()->whereKey(502)->update(['birth_date' => now()->subYears(18)->toDateString()]);
        $this->rate(502, $this->dough, 3)->assertJsonPath('data.alert', false);
    }

    public function test_a_rating_can_be_removed(): void
    {
        $this->rate(501, $this->register, 3)->assertOk();

        $this->deleteJson("/api/v1/stores/03759-00001/employees/501/skills/{$this->register->id}", [], $this->headers())
            ->assertNoContent();
        $this->deleteJson("/api/v1/stores/03759-00001/employees/501/skills/{$this->register->id}", [], $this->headers())
            ->assertNotFound();
    }

    public function test_the_overview_lists_the_stores_employees_and_their_alerts(): void
    {
        $this->rate(501, $this->dough, 5)->assertOk();
        $this->rate(502, $this->dough, 2)->assertOk();
        $this->rate(502, $this->register, 4)->assertOk();

        $data = $this->getJson('/api/v1/stores/03759-00001/skills/overview', $this->headers())
            ->assertOk()
            ->json('data');

        $this->assertCount(6, $data['skills']);

        // Only this store's employees, by first name.
        $this->assertSame(['502', '501', '503'], array_column($data['employees'], 'id'));

        $employees = collect($data['employees'])->keyBy('id');
        $this->assertSame([(string) $this->dough->id], $employees[502]['alert_skill_ids']);
        $this->assertSame(4, $employees[502]['ratings'][(string) $this->register->id]['rating']);
        $this->assertSame([], $employees[501]['alert_skill_ids']);
        $this->assertSame([], $employees[503]['ratings']);
    }
}
