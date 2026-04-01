<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
       Schema::create('schedule_template_details', function (Blueprint $table) {
            $table->id();

            $table->foreignId('schedule_template_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('employee_id')
                ->constrained()
                ->restrictOnDelete();

            $table->enum('day_of_week', [
                'sunday','monday','tuesday','wednesday','thursday','friday','saturday'
            ]);

            $table->time('start_time');
            $table->time('end_time');

            $table->foreignId('skill_id')->constrained()->restrictOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_template_details');
    }
};
