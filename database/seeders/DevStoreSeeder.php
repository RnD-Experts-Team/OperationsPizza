<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\EmployeeStore;
use App\Models\HumanityLocation;
use App\Models\HumanityPosition;
use App\Models\HumanityPositionMap;
use App\Models\Store;
use App\Models\TcpJobCode;
use App\Models\TcpLocation;
use Illuminate\Database\Seeder;

/**
 * A complete, workable local store for development against the fake drivers:
 * store + schedule settings + Humanity mapping + TCP catalog + two employees
 * with external links. Everything the schedule/week endpoint, a shift write,
 * and a clock-in need — without hand-building the fixture every time (or
 * being tempted to point at live data instead).
 *
 *   php artisan db:seed --class=DevStoreSeeder
 *
 * Idempotent. Mirrors the real data's shapes: store_number "03795-000xx",
 * TCP location named by store_number, per-store job codes with descriptions
 * like "Crew Member - 3795-99".
 */
class DevStoreSeeder extends Seeder
{
    public function run(): void
    {
        $store = Store::query()->updateOrCreate(
            ['id' => 9001],
            ['store_number' => '03795-99999', 'name' => 'Dev Test Store']
        );
        $store->settings();

        // Humanity side: explicit mapping, as humanity:map-location would write.
        HumanityLocation::query()->updateOrCreate(
            ['store_id' => $store->id],
            ['humanity_location_id' => 'DEVLOC1', 'name' => 'Dev Test Store', 'timezone' => 'America/Chicago']
        );
        HumanityPosition::query()->updateOrCreate(
            ['humanity_position_id' => 'DEVPOS1'],
            ['humanity_location_id' => 'DEVLOC1', 'name' => 'Crew Member', 'is_active' => true]
        );
        HumanityPositionMap::query()->updateOrCreate(
            ['store_id' => $store->id, 'position_label' => null],
            ['humanity_position_id' => 'DEVPOS1', 'is_default' => true]
        );
        HumanityPositionMap::query()->updateOrCreate(
            ['store_id' => $store->id, 'position_label' => 'Crew Member'],
            ['humanity_position_id' => 'DEVPOS1', 'is_default' => false]
        );

        // TCP side: name-bound location + per-store job codes, as
        // tcp:sync-catalog would write them.
        TcpLocation::query()->updateOrCreate(
            ['store_id' => $store->id],
            ['tcp_location_id' => '99999001', 'name' => '03795-99999', 'last_synced_at' => now()]
        );

        foreach ([
            ['37959901', 'Crew Member - 3795-99'],
            ['37959903', 'Manager - 3795-99'],
        ] as [$id, $description]) {
            TcpJobCode::query()->updateOrCreate(
                ['tcp_job_code_id' => $id],
                [
                    'description' => $description,
                    'store_number' => $store->store_number,
                    'clockable' => true,
                    'is_active' => true,
                    'last_synced_at' => now(),
                ]
            );
        }

        foreach ([
            [9501, 'Ada', 'Lovelace', 'Crew Member'],
            [9502, 'Grace', 'Hopper', 'Manager'],
        ] as [$id, $first, $last, $positionLabel]) {
            $employee = Employee::query()->updateOrCreate(
                ['id' => $id],
                [
                    'first_name' => $first,
                    'last_name' => $last,
                    'active' => true,
                    'current_status' => 'hired',
                    'position_label' => $positionLabel,
                    'hourly_rate' => '16.0000',
                    // Both external links present, in the shapes the real
                    // account uses (TCP-native id; Humanity's own id).
                    'tcp_employee_id' => (string) ($id + 9000000),
                    'humanity_employee_id' => (string) ($id + 80000),
                ]
            );

            EmployeeStore::query()->updateOrCreate(
                ['employee_id' => $employee->id, 'store_number' => $store->store_number],
                ['store_id' => $store->id, 'status' => 'hired', 'active' => true]
            );
        }

        $this->command?->info('Dev store 03795-99999 ready: 2 employees, Humanity + TCP mappings, clockable job codes.');
    }
}
