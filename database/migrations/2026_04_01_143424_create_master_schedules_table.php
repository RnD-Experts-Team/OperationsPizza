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
       Schema::create('master_schedule', function (Blueprint $table) {
            $table->id();

            $table->foreignId('store_id')->constrained()->restrictOnDelete();

            $table->date('start_date');
            $table->date('end_date');

            $table->boolean('published')->default(false);

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_schedules');
    }
};
