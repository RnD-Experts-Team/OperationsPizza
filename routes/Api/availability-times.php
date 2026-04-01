<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AvailabilityTimeController;
Route::middleware('auth:sanctum')->group(function () {

    Route::apiResource('availability-times', AvailabilityTimeController::class);
});