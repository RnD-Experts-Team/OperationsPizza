<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_template_stores', function (Blueprint $table) {
            $table->id();

            $table->foreignId('schedule_template_id')
                ->constrained('schedule_templates')
                ->cascadeOnDelete();

            $table->foreignId('store_id')
                ->constrained('stores')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['schedule_template_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_template_stores');
    }
};