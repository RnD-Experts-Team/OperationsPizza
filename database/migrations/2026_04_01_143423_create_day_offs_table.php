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
      Schema::create('days_off', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->date('date');
            $table->timestamp('requested_at')->nullable();

            $table->enum('type', ['sick day', 'unavailable', 'pto', 'vto']);

            $table->text('note')->nullable();

            $table->enum('acceptedStatus', ['pending', 'approved', 'rejected']);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('day_offs');
    }
};
