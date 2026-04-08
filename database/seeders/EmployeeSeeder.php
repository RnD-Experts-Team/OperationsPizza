<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Store;
use Illuminate\Database\Seeder;

class EmployeeSeeder extends Seeder
{
    public function run(): void
    {
        $store1 = Store::where('store', 1)->first();
        $store2 = Store::where('store', 2)->first();
        $store3 = Store::where('store', 3)->first();

        Employee::create([
            'id'=>1,

            'store_id' => $store1->id,
            'name' => 'Ahmed Ali',
            'phone' => '0599000001',
            'email' => 'ahmed@example.com',
            'hire_date' => '2024-01-15',
            'status' => 'resignation',
        ]);

        Employee::create([

            'id'=>2,

            'store_id' => $store1->id,
            'name' => 'Sara Khaled',
            'phone' => '0599000002',
            'email' => 'sara@example.com',
            'hire_date' => '2023-09-10',
            'status' => 'resignation',
        ]);

        Employee::create([
            'id'=>3,

            'store_id' => $store2->id,
            'name' => 'Mohammed Sameer',
            'phone' => '0599000003',
            'email' => 'mohammed@example.com',
            'hire_date' => '2022-05-20',
            'status' => 'resignation',
        ]);

        Employee::create([
            'id'=>4,

            'store_id' => $store3->id,
            'name' => 'Layan Omar',
            'phone' => '0599000004',
            'email' => 'layan@example.com',
            'hire_date' => '2024-03-01',
            'status' => 'resignation',
        ]);
         Employee::create([

            'id'=>5,

            'store_id' => $store3->id,
            'name' => 'ss Omar',
            'phone' => '0599000004',
            'email' => 'lay2an@example.com',
            'hire_date' => '2024-03-01',
            'status' => 'resignation',
        ]);
         Employee::create([
            'id'=>6,

            'store_id' => $store3->id,
            'name' => 'Laya3n Omar',
            'phone' => '0599000004',
            'email' => 'laya3n@example.com',
            'hire_date' => '2024-03-01',
            'status' => 'resignation',
        ]);
         Employee::create([
            'id'=>7,

            'store_id' => $store3->id,
            'name' => 'Layan Omar',
            'phone' => '0599000004',
            'email' => 'laya4n@example.com',
            'hire_date' => '2024-03-01',
            'status' => 'resignation',
        ]);
    }
}