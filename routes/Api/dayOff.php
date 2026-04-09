<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\DayOffController;

Route::post('days-off', [DayOffController::class, 'store']);

Route::middleware('auth:sanctum')->group(function () {

    Route::get('stores/{store}/days-off', [DayOffController::class, 'index']);
    Route::put('stores/{store}/days-off/{day_off}', [DayOffController::class, 'update']);
    Route::delete('stores/{store}/days-off/{day_off}', [DayOffController::class, 'destroy']);
});