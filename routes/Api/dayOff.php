<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\DayOffController;

Route::post('days-off', [DayOffController::class, 'store']);


Route::middleware('auth:sanctum')->group(function () {

    Route::get('days-off', [DayOffController::class, 'index']);

    Route::get('days-off/{id}', [DayOffController::class, 'show']);

    Route::put('days-off/{id}', [DayOffController::class, 'update']);

    Route::delete('days-off/{id}', [DayOffController::class, 'destroy']);
});