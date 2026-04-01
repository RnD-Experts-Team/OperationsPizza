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
       Schema::create('schedules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->foreignId('schedule_week_id')
                ->constrained('master_schedule')
                ->cascadeOnDelete();

            $table->date('date');

            $table->time('start_time');
            $table->time('end_time');

            $table->time('actual_start_time')->nullable();
            $table->time('actual_end_time')->nullable();

            $table->foreignId('skill_id')->constrained()->restrictOnDelete();

            $table->foreignId('edited_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
