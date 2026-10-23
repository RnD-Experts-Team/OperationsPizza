<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employee skills: a short list of stations, and a 1–5 star rating per
 * employee per skill. One rating per employee, shared by every store they
 * work at — it describes the person, not a store's opinion of them.
 *
 * `min_age` is the one restriction we have: an employee younger than it should
 * not work on, be near, or be trained on that station. It only WARNS (on the
 * employee's skills and the store overview); nothing is refused.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->unsignedTinyInteger('min_age')->nullable();
            $table->text('restriction_note')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_skills', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('employee_id');
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();

            $table->foreignId('skill_id')->constrained('skills')->cascadeOnDelete();

            $table->unsignedTinyInteger('rating');
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['employee_id', 'skill_id']);
        });

        $minorNote = 'Employees under 18 must not work on, be near, or be trained on this station.';
        $now = now();

        DB::table('skills')->insert(array_map(fn (array $skill) => $skill + [
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            ['name' => 'Opening', 'min_age' => null, 'restriction_note' => null],
            ['name' => 'Closing', 'min_age' => null, 'restriction_note' => null],
            ['name' => 'Dough', 'min_age' => 18, 'restriction_note' => $minorNote],
            ['name' => 'Making', 'min_age' => null, 'restriction_note' => null],
            ['name' => 'Landing', 'min_age' => 18, 'restriction_note' => $minorNote],
            ['name' => 'Register', 'min_age' => null, 'restriction_note' => null],
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_skills');
        Schema::dropIfExists('skills');
    }
};
