<?php

namespace Database\Seeders;

use App\Models\Store;
use Illuminate\Database\Seeder;

class StoreSeeder extends Seeder
{
    public function run(): void
    {
        Store::create([
            'id'=>1,
            'store' => 1,
        ]);

        Store::create([
            'id'=>2,

            'store' => 2,
        ]);

        Store::create([
            'id'=>3,

            'store' => 3,
        ]);
    }
}