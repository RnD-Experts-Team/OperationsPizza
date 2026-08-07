<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            // pizzasys store id — NOT auto-incrementing. Replicated from
            // auth.v1.store.* events.
            $table->unsignedBigInteger('id')->primary();

            // pizzasys calls this `store_id` (e.g. "03759-00001"). It is the
            // {storeId} segment in our API routes, matching HiringPizza.
            $table->string('store_number', 32)->unique();

            $table->string('name', 190)->nullable();

            // Store events do NOT carry a timezone (verified in pizzasys
            // StoreManagementService::createStore). StoreCreatedHandler reads
            // data.store.metadata.timezone, then config, and logs loudly on
            // fallback — a wrong timezone here is silent, week-long corruption
            // of every shift time.
            $table->string('timezone', 64)->default('America/Chicago');

            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
