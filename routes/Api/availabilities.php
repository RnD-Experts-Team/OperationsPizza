<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AvailabilityController;
Route::middleware('auth:sanctum')->group(function () {

Route::apiResource('availabilities', AvailabilityController::class);
});