<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brings back the one demographic the slim-down dropped: the birth date.
 *
 * Employee skills warn when someone under a skill's minimum age (18 for Dough
 * and Landing) is rated on it. Age is worked out from this date when read, so
 * the warning clears on the employee's birthday without anything rewriting a
 * flag. The API never returns the date itself, only whether they are a minor.
 *
 * Nullable: HiringPizza's own column is. NOT backfilled here — existing
 * employees get it from the next event about them, or all at once with
 *
 *     php artisan hiring:republish-employees   (in HiringPizza)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->date('birth_date')->nullable()->after('last_name');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('birth_date');
        });
    }
};
