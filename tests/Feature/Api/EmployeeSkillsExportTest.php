<?php

namespace Tests\Feature\Api;

use App\Models\Employee;
use App\Models\EmployeeSkill;
use App\Models\EmployeeStore;
use App\Models\Skill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GET /api/v1/skills/export/csv behind auth.secret.key.
 *
 * Marco (store 1) rated Dough 4 on 2026-10-01 and Register 5 on 2026-10-20.
 * Lia (stores 1 and 2) rated Making 3 on 2026-10-10, and is born 2010-05-04.
 * Gone (store 2) is inactive and rated on Register, so never appears.
 */
class EmployeeSkillsExportTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/skills/export/csv';

    private const STORE_1 = '03759-00001';

    private const STORE_2 = '03759-00002';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.x_secret_key' => 'test-secret']);

        $dough = Skill::query()->where('name', 'Dough')->firstOrFail();
        $making = Skill::query()->where('name', 'Making')->firstOrFail();
        $register = Skill::query()->where('name', 'Register')->firstOrFail();

        $this->employee(601, 'Marco', '1990-02-03', true, [self::STORE_1]);
        $this->employee(602, 'Lia', '2010-05-04', true, [self::STORE_1, self::STORE_2]);
        $this->employee(603, 'Gone', null, false, [self::STORE_2]);
        $this->employee(604, 'Unrated', null, true, [self::STORE_1]);

        $this->rating(601, $dough, 4, '2026-10-01 08:00:00');
        $this->rating(601, $register, 5, '2026-10-20 23:30:00');
        $this->rating(602, $making, 3, '2026-10-10 12:00:00');
        $this->rating(603, $register, 2, '2026-10-10 12:00:00');
    }

    /** @param  array<int, string>  $stores */
    private function employee(int $id, string $firstName, ?string $birthDate, bool $active, array $stores): void
    {
        Employee::query()->create([
            'id' => $id,
            'first_name' => $firstName,
            'last_name' => 'Test',
            'birth_date' => $birthDate,
            'active' => $active,
            'current_status' => 'hired',
        ]);

        foreach ($stores as $store) {
            EmployeeStore::query()->create([
                'employee_id' => $id,
                'store_number' => $store,
                'status' => 'hired',
                'active' => true,
            ]);
        }
    }

    private function rating(int $employeeId, Skill $skill, int $rating, string $at): void
    {
        $row = EmployeeSkill::query()->create(['employee_id' => $employeeId, 'skill_id' => $skill->id, 'rating' => $rating]);
        DB::table('employee_skills')->where('id', $row->id)->update(['created_at' => $at, 'updated_at' => $at]);
    }

    /** @return array<int, array<int, string>> the CSV rows, header included */
    private function csv(string $query = ''): array
    {
        $response = $this->withHeaders(['X-Secret-Key' => 'test-secret'])->get(self::URL.$query)->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));

        return array_map('str_getcsv', explode("\n", trim($response->streamedContent())));
    }

    public function test_it_needs_the_secret_key(): void
    {
        $this->get(self::URL)->assertUnauthorized();
        $this->withHeaders(['X-Secret-Key' => 'wrong'])->get(self::URL)->assertUnauthorized();

        config(['services.x_secret_key' => '']);
        $this->withHeaders(['X-Secret-Key' => 'test-secret'])->get(self::URL)->assertStatus(500);
    }

    public function test_no_dates_and_no_stores_is_every_rating_of_every_store(): void
    {
        $rows = $this->csv();

        $this->assertSame(['Store', 'Employee ID', 'Employee', 'Birth date', 'Skill', 'Rating', 'Rated on'], $rows[0]);
        $this->assertSame([
            [self::STORE_1, '602', 'Lia Test', '2010-05-04', 'Making', '3', '2026-10-10'],
            [self::STORE_1, '601', 'Marco Test', '1990-02-03', 'Dough', '4', '2026-10-01'],
            [self::STORE_1, '601', 'Marco Test', '1990-02-03', 'Register', '5', '2026-10-20'],
            // Lia works at two stores: the same rating under each.
            [self::STORE_2, '602', 'Lia Test', '2010-05-04', 'Making', '3', '2026-10-10'],
        ], array_slice($rows, 1));
    }

    public function test_stores_narrow_it_as_a_list_or_an_array(): void
    {
        $this->assertCount(1, array_slice($this->csv('?stores='.self::STORE_2), 1));
        $this->assertCount(1, array_slice($this->csv('?stores[]='.self::STORE_2), 1));
        $this->assertCount(4, array_slice($this->csv('?stores='.self::STORE_1.','.self::STORE_2), 1));
        $this->assertCount(4, array_slice($this->csv('?stores='), 1));
    }

    public function test_the_dates_keep_the_ratings_saved_in_the_span_inclusive(): void
    {
        $skills = fn (array $rows) => array_column(array_slice($rows, 1), 4);

        $this->assertSame(['Making', 'Making'], $skills($this->csv('?start_date=2026-10-10&end_date=2026-10-10')));
        // The span and a store together; the last day counts to its end (23:30).
        $this->assertSame(
            ['Making', 'Register'],
            $skills($this->csv('?stores='.self::STORE_1.'&start_date=2026-10-02&end_date=2026-10-20'))
        );
        // Only a start: everything from then on; only an end: everything up to it.
        $this->assertSame(['Register'], $skills($this->csv('?start_date=2026-10-11')));
        $this->assertSame(['Dough'], $skills($this->csv('?end_date=2026-10-05')));
    }

    public function test_a_bad_range_is_422(): void
    {
        $this->withHeaders(['X-Secret-Key' => 'test-secret'])
            ->get(self::URL.'?start_date=2026-10-20&end_date=2026-10-01')->assertStatus(422);
        $this->withHeaders(['X-Secret-Key' => 'test-secret'])
            ->get(self::URL.'?start_date=20-10-2026')->assertStatus(422);
    }
}
